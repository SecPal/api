<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BewacherregisterExportNotReadyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportEmployeeBwrRequest;
use App\Http\Requests\IndexEmployeeRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeBwrStatusRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\OrganizationalUnitClosure;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Services\BewacherregisterExportService;
use App\Services\EmployeeComplianceService;
use App\Services\EmployeeLifecycleService;
use App\Services\EmployeeOnboardingInvitationService;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * EmployeeController handles Employee resource CRUD operations.
 *
 * All operations require Sanctum authentication and are protected by EmployeePolicy.
 */
class EmployeeController extends Controller
{
    /**
     * Display a listing of employees.
     *
     * GET /v1/employees
     *
     * Supports filtering by:
     * - status (applicant, pre_contract, active, on_leave, terminated)
     * - organizational_unit_id
     * - search (email, employee_number)
     */
    public function index(IndexEmployeeRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $employees = $this->buildEmployeeIndexQuery($request)
            ->with(['user', 'organizationalUnit'])
            ->paginate($request->integer('per_page', 15));

        return EmployeeResource::collection($employees);
    }

    /**
     * Display employees with active compliance alerts for overview use cases.
     */
    public function complianceAlerts(IndexEmployeeRequest $request, EmployeeComplianceService $complianceService): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        /** @var string|null $complianceStatus */
        $complianceStatus = $request->input('compliance_status');

        $perPage = $request->integer('per_page', 15);
        $page = LengthAwarePaginator::resolveCurrentPage();

        $employees = $this->buildEmployeeIndexQuery($request)
            ->whereIn('status', [
                Employee::STATUS_PRE_CONTRACT,
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
            ])
            ->with(['user', 'organizationalUnit'])
            ->get()
            ->filter(fn (Employee $employee): bool => $complianceService->hasAlerts($employee, $complianceStatus))
            ->values();

        $paginatedEmployees = new LengthAwarePaginator(
            $employees->forPage($page, $perPage)->values(),
            $employees->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return EmployeeResource::collection($paginatedEmployees);
    }

    /**
     * Build the common employee index query with tenant, scope, and filter handling.
     *
     * @return Builder<Employee>
     */
    private function buildEmployeeIndexQuery(IndexEmployeeRequest $request): Builder
    {
        /** @var User $user */
        $user = $request->user();

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        $query = Employee::where('tenant_id', $tenantId);

        // Apply organizational scope filtering for scoped users (e.g., managers)
        $user->loadMissing('organizationalScopes');
        if ($user->organizationalScopes->isNotEmpty()) {
            $this->applyScopedEmployeeVisibility($query, $user);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by organizational unit
        if ($request->has('organizational_unit_id')) {
            $query->where('organizational_unit_id', $request->input('organizational_unit_id'));
        }

        // Search by email or employee_number
        if ($request->has('search')) {
            /** @var string $search */
            $search = $request->input('search');
            $escapedSearch = LikePattern::escape($search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('email', 'like', "%{$escapedSearch}%")
                    ->orWhere('employee_number', 'like', "%{$escapedSearch}%");
            });
        }

        return $query;
    }

    /**
     * Apply scoped employee visibility so collection access matches detail authorization.
     *
     * @param  Builder<Employee>  $query
     */
    private function applyScopedEmployeeVisibility(Builder $query, User $user): void
    {
        // Scopes already loaded by buildEmployeeIndexQuery; build unit->scopes map in memory.
        /** @var Collection<int, UserInternalOrganizationalScope> $loadedScopes */
        $loadedScopes = $user->organizationalScopes;

        /** @var array<string, Collection<int, UserInternalOrganizationalScope>> $unitToScopes */
        $unitToScopes = $loadedScopes
            ->groupBy('organizational_unit_id')
            ->map(fn (Collection $group): Collection => $group->values())
            ->all();

        $directUnitIds = collect(array_keys($unitToScopes));

        // Resolve descendant units for include_descendants scopes in a single batch query.
        /** @var Collection<string, UserInternalOrganizationalScope> $descendantScopesByAncestorId */
        $descendantScopesByAncestorId = $loadedScopes
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => (bool) $scope->include_descendants)
            ->keyBy('organizational_unit_id');

        if ($descendantScopesByAncestorId->isNotEmpty()) {
            OrganizationalUnitClosure::whereIn('ancestor_id', $descendantScopesByAncestorId->keys())
                ->where('depth', '>', 0)
                ->select(['ancestor_id', 'descendant_id'])
                ->orderBy('ancestor_id')
                ->orderBy('descendant_id')
                ->each(function (OrganizationalUnitClosure $closure) use (
                    $directUnitIds,
                    $descendantScopesByAncestorId,
                    &$unitToScopes,
                ): void {
                    $descendantId = $closure->descendant_id;

                    // Direct scope takes precedence over inherited descendant scope.
                    if ($directUnitIds->contains($descendantId)) {
                        return;
                    }

                    /** @var UserInternalOrganizationalScope|null $ancestorScope */
                    $ancestorScope = $descendantScopesByAncestorId->get($closure->ancestor_id);

                    if (! $ancestorScope instanceof UserInternalOrganizationalScope) {
                        return;
                    }

                    if (! isset($unitToScopes[$descendantId])) {
                        /** @var Collection<int, UserInternalOrganizationalScope> $emptyCollection */
                        $emptyCollection = collect();
                        $unitToScopes[$descendantId] = $emptyCollection;
                    }

                    $unitToScopes[$descendantId]->push($ancestorScope);
                });
        }

        // Filter to units with at least one read-accessible scope.
        /** @var Collection<string, Collection<int, UserInternalOrganizationalScope>> $visibleUnits */
        $visibleUnits = collect($unitToScopes)
            ->map(fn (Collection $unitScopes): Collection => $unitScopes
                ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel('read'))
                ->values()
            )
            ->filter(fn (Collection $readableScopes): bool => $readableScopes->isNotEmpty());

        if ($visibleUnits->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $scopeQuery) use ($visibleUnits, $user): void {
            foreach ($visibleUnits as $unitId => $unitScopes) {
                $scopeQuery->orWhere(function (Builder $unitQuery) use ($unitId, $unitScopes, $user): void {
                    $unitQuery->where('organizational_unit_id', $unitId);

                    $this->applyManagementLevelVisibilityConstraints($unitQuery, $unitScopes, $user);
                });
            }
        });
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  Collection<int, UserInternalOrganizationalScope>  $scopes
     */
    private function applyManagementLevelVisibilityConstraints(Builder $query, Collection $scopes, User $user): void
    {
        if ($this->hasFullyViewableScope($scopes)) {
            return;
        }

        $allowsSelfAccess = $scopes->contains(
            fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access
        );

        $query->where(function (Builder $visibilityQuery) use ($allowsSelfAccess, $scopes, $user): void {
            if ($allowsSelfAccess) {
                $visibilityQuery->orWhere('user_id', $user->id);
            }

            foreach ($scopes as $scope) {
                $this->addViewableManagementLevelConstraint($visibilityQuery, $scope);
            }
        });
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $scopes
     */
    private function hasFullyViewableScope(Collection $scopes): bool
    {
        return $scopes->contains(function (UserInternalOrganizationalScope $scope): bool {
            $minimum = $scope->min_viewable_rank;
            $maximum = $scope->max_viewable_rank;

            return ($minimum === null || $minimum === 0) && $maximum === null;
        });
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function addViewableManagementLevelConstraint(Builder $query, UserInternalOrganizationalScope $scope): void
    {
        $minimum = $scope->min_viewable_rank;
        $maximum = $scope->max_viewable_rank;

        if (($minimum === null || $minimum === 0) && $maximum === 0) {
            $query->orWhere('management_level', 0);

            return;
        }

        if ($maximum === 0) {
            return;
        }

        $query->orWhere(function (Builder $rankQuery) use ($minimum, $maximum): void {
            $lowerBound = ($minimum === null || $minimum === 0) ? 1 : $minimum;

            $rankQuery->where('management_level', '>=', $lowerBound);

            if ($maximum !== null) {
                $rankQuery->where('management_level', '<=', $maximum);
            }
        });
    }

    /**
     * Store a newly created employee.
     *
     * POST /v1/employees
     *
     * Creates employee and triggers EmployeeObserver to create user account if status = pre_contract.
     * Onboarding invitations may only be requested while the employee is in pre_contract status.
     */
    public function store(StoreEmployeeRequest $request, EmployeeOnboardingInvitationService $invitationService): JsonResponse
    {
        $this->authorize('create', Employee::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $shouldSendInvitation = (bool) ($validated['send_invitation'] ?? false);
        unset($validated['send_invitation']);

        // Prepare data with tenant_id FIRST (required for encryption cast to work)
        $employee = DB::transaction(function () use ($tenantId, $validated): Employee {
            TenantKey::query()->select('id')->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $addresses = $validated['addresses'] ?? [];
            unset($validated['addresses']);

            $data = ['tenant_id' => $tenantId];
            $data['employee_number'] = $this->generateEmployeeNumber($tenantId);

            if ($validated['status'] === Employee::STATUS_PRE_CONTRACT) {
                $data['onboarding_steps'] = Employee::getDefaultOnboardingSteps();
            }

            $lifecycleStatus = is_string($validated['status']) ? $validated['status'] : Employee::STATUS_ACTIVE;
            $data['onboarding_workflow_status'] = Employee::defaultWorkflowStatusForLifecycleStatus($lifecycleStatus)
                ?? Employee::WORKFLOW_STATUS_ACTIVE;

            $employee = Employee::create(array_merge($data, $validated));
            /** @var array<int, array<string, mixed>> $normalizedAddresses */
            $normalizedAddresses = is_array($addresses) ? $addresses : [];
            $this->syncEmployeeAddresses($employee, $normalizedAddresses);

            return $employee;
        });

        if ($shouldSendInvitation) {
            $employee = $invitationService->send($employee);
        }

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();
        $freshEmployee->load(['user', 'organizationalUnit', 'addresses']);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified employee.
     *
     * GET /api/v1/employees/{employee}
     */
    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->load(['user', 'organizationalUnit', 'employeeQualifications.qualification', 'documents', 'addresses']);

        return response()->json([
            'data' => new EmployeeResource($employee),
        ]);
    }

    /**
     * Update the specified employee.
     *
     * PATCH /api/v1/employees/{employee}
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        DB::transaction(function () use ($employee, $validated): void {
            $addressPayload = null;
            if (array_key_exists('addresses', $validated)) {
                $addressPayload = $validated['addresses'];
                unset($validated['addresses']);
            }

            if ($validated !== []) {
                $employee->update($validated);
            }

            if ($addressPayload !== null) {
                /** @var array<int, array<string, mixed>> $normalizedPayload */
                $normalizedPayload = is_array($addressPayload) ? $addressPayload : [];
                $this->syncEmployeeAddresses($employee, $normalizedPayload);
            }
        });

        // Note: lifecycle transitions (activate, placeOnLeave, returnFromLeave, terminate)
        // are handled by dedicated endpoints. The observer handles passive side effects only
        // (blind index recomputation, user account creation for pre_contract status).

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();
        $freshEmployee->load(['user', 'organizationalUnit', 'addresses']);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * Generate a BWR export for an employee and transition the BWR status to pending.
     */
    public function exportBwr(ExportEmployeeBwrRequest $request, Employee $employee, BewacherregisterExportService $exportService): JsonResponse
    {
        $this->authorize('update', $employee);

        $format = $request->string('format')->toString();
        if ($format === '') {
            $format = 'csv';
        }

        if ($employee->bwr_status !== 'not_registered') {
            return response()->json([
                'message' => __('BWR export is only available for employees with status not_registered.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $export = $format === 'xml'
                ? $exportService->exportXml($employee, $user->name)
                : $exportService->exportCsv($employee, $user->name);
        } catch (BewacherregisterExportNotReadyException $exception) {
            return response()->json([
                'message' => __('Employee is not ready for BWR export.'),
                'errors' => $exception->errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $employee->update([
            'bwr_status' => 'pending',
            'bwr_submission_date' => now()->toDateString(),
        ]);

        activity('employee_changes')
            ->causedBy($request->user())
            ->performedOn($employee)
            ->withProperties([
                'format' => $format,
                'file_name' => $export['file_name'],
                'file_path' => $export['path'],
                'file_size_bytes' => $export['file_size_bytes'],
                'old_bwr_status' => 'not_registered',
                'new_bwr_status' => 'pending',
            ])
            ->log('BWR export generated');

        return response()->json([
            'data' => [
                'employee_id' => $employee->id,
                'status' => 'pending',
                'format' => $format,
                'download_url' => route('employees.bwr-exports.download', [
                    'employee' => $employee,
                    'file' => $export['file_name'],
                ]),
            ],
        ]);
    }

    /**
     * Download a previously generated BWR export file.
     */
    public function downloadBwrExport(Employee $employee, string $file, BewacherregisterExportService $exportService): Response
    {
        $this->authorize('update', $employee);

        $path = $exportService->downloadPath($employee, $file);
        if (! Storage::disk('local')->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $content = Storage::disk('local')->get($path) ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = $extension === 'xml' ? 'application/xml; charset=UTF-8' : 'text/csv; charset=UTF-8';

        return response($content)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'attachment; filename="'.basename($path).'"');
    }

    /**
     * Update the BWR registration status for an employee.
     */
    public function updateBwrStatus(UpdateEmployeeBwrStatusRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validated();
        $newStatus = $validated['status'];
        $oldStatus = $employee->bwr_status;

        if (! is_string($newStatus)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($oldStatus !== $newStatus && ! $this->isAllowedBwrStatusTransition($oldStatus, $newStatus)) {
            return response()->json([
                'message' => sprintf('BWR status transition from %s to %s is not allowed.', $oldStatus, $newStatus),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $updates = [
            'bwr_status' => $newStatus,
        ];

        if (array_key_exists('bwr_id', $validated)) {
            $updates['bwr_id'] = $validated['bwr_id'];
        }

        if (array_key_exists('notes', $validated)) {
            $updates['bwr_notes'] = $validated['notes'];
        }

        if ($newStatus === 'active' && $employee->bwr_registered_at === null) {
            $updates['bwr_registered_at'] = now();
        }

        $employee->update($updates);

        activity('employee_changes')
            ->causedBy($user)
            ->performedOn($employee)
            ->withProperties([
                'old_bwr_status' => $oldStatus,
                'new_bwr_status' => $newStatus,
                'bwr_id' => $employee->bwr_id,
                'notes' => $employee->bwr_notes,
            ])
            ->log('BWR status updated');

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();
        $freshEmployee->load(['user', 'organizationalUnit', 'addresses']);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * Remove the specified employee (soft delete).
     *
     * DELETE /api/v1/employees/{employee}
     */
    public function destroy(Employee $employee): Response
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return response()->noContent();
    }

    /**
     * Activate employee (transition to active status).
     *
     * POST /api/v1/employees/{employee}/activate
     *
     * Requires onboarding_completed = true and contract_start_date <= today.
     */
    public function activate(Employee $employee): JsonResponse
    {
        $this->authorize('activate', $employee);

        if ($employee->status !== Employee::STATUS_PRE_CONTRACT) {
            return response()->json([
                'message' => __('Employee must be in pre_contract status to activate'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $employee->canActivate()) {
            return response()->json([
                'message' => __('Cannot activate: onboarding must be completed, workflow must be ready for activation, and contract start date must have passed'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Employee $freshEmployee */
        $freshEmployee = app(EmployeeLifecycleService::class)->activate($employee);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * Place an employee on leave and reduce runtime access to the read-only baseline.
     */
    public function placeOnLeave(Employee $employee): JsonResponse
    {
        $this->authorize('placeOnLeave', $employee);

        if ($employee->status !== Employee::STATUS_ACTIVE) {
            return response()->json([
                'message' => __('Employee must be active to be placed on leave'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Employee $freshEmployee */
        $freshEmployee = app(EmployeeLifecycleService::class)->placeOnLeave($employee);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * Restore a previously on-leave employee to the active runtime access model.
     */
    public function returnFromLeave(Employee $employee): JsonResponse
    {
        $this->authorize('returnFromLeave', $employee);

        if ($employee->status !== Employee::STATUS_ON_LEAVE) {
            return response()->json([
                'message' => __('Employee must be on leave to restore active access'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Employee $freshEmployee */
        $freshEmployee = app(EmployeeLifecycleService::class)->returnFromLeave($employee);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * Terminate employee (transition to terminated status).
     *
     * POST /api/v1/employees/{employee}/terminate
     *
     * Immediately deactivates user account and revokes all roles.
     */
    public function terminate(Employee $employee): JsonResponse
    {
        $this->authorize('terminate', $employee);

        if (! $employee->canTerminate()) {
            return response()->json([
                'message' => __('Cannot terminate: employee must be active or on_leave'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var Employee $freshEmployee */
        $freshEmployee = app(EmployeeLifecycleService::class)->terminate($employee);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $addressRows
     */
    private function syncEmployeeAddresses(Employee $employee, array $addressRows): void
    {
        $employee->addresses()->delete();

        foreach ($addressRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            EmployeeAddress::query()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => $this->nullableRequestString($row['street'] ?? null),
                'house_number' => $this->nullableRequestString($row['house_number'] ?? null),
                'postal_code' => $this->nullableRequestString($row['postal_code'] ?? null),
                'city' => $this->nullableRequestString($row['city'] ?? null),
                'supplement' => $this->nullableRequestString($row['supplement'] ?? null),
                'country' => $this->nullableRequestString($row['country'] ?? null),
                'state' => $this->nullableRequestString($row['state'] ?? null),
                'resided_from' => $this->nullableRequestDate($row['resided_from'] ?? null),
                'resided_until' => $this->nullableRequestDate($row['resided_until'] ?? null),
            ]);
        }
    }

    private function nullableRequestString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function nullableRequestDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Generate unique employee number for tenant.
     *
     * Format: EMP-YYYY-####
     */
    private function generateEmployeeNumber(int $tenantId): string
    {
        $year = now()->year;
        $prefix = "EMP-{$year}-";

        // Get the latest employee number for this tenant and year
        $latestEmployee = Employee::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('employee_number', 'like', "{$prefix}%")
            ->orderBy('employee_number', 'desc')
            ->lockForUpdate()
            ->first();

        if ($latestEmployee) {
            // Extract the sequence number and increment
            $lastNumber = (int) substr($latestEmployee->employee_number, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            // First employee for this year
            $nextNumber = 1;
        }

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function isAllowedBwrStatusTransition(string $currentStatus, string $newStatus): bool
    {
        $allowedTransitions = [
            'pending' => ['active', 'suspended', 'revoked'],
            'active' => ['suspended', 'revoked'],
            'suspended' => ['active', 'revoked'],
            'revoked' => ['active'],
        ];

        return in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true);
    }
}
