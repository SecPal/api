<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
     * GET /api/v1/employees
     *
     * Supports filtering by:
     * - status (pre_contract, active, on_leave, terminated)
     * - organizational_unit_id
     * - search (name, email, employee_number)
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        $query = Employee::where('tenant_id', $tenantId);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by organizational unit
        if ($request->has('organizational_unit_id')) {
            $query->where('organizational_unit_id', $request->input('organizational_unit_id'));
        }

        // Search by name, email, or employee_number
        if ($request->has('search')) {
            /** @var string $search */
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%");
            });
        }

        $employees = $query->with(['user', 'organizationalUnit'])
            ->paginate($request->integer('per_page', 15));

        return EmployeeResource::collection($employees);
    }

    /**
     * Store a newly created employee.
     *
     * POST /api/v1/employees
     *
     * Creates employee and triggers EmployeeObserver to create user account if status = pre_contract.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Prepare data with tenant_id FIRST (required for encryption cast to work)
        $data = ['tenant_id' => $tenantId];

        // Generate employee_number (format: EMP-YYYY-####)
        $data['employee_number'] = $this->generateEmployeeNumber($tenantId);

        // Initialize onboarding steps if status = pre_contract
        if ($validated['status'] === Employee::STATUS_PRE_CONTRACT) {
            $data['onboarding_steps'] = Employee::getDefaultOnboardingSteps();
        }

        // Merge remaining validated data
        $data = array_merge($data, $validated);

        $employee = Employee::create($data);

        // Observer will handle user account creation if status = pre_contract

        return response()->json([
            'data' => new EmployeeResource($employee->load(['user', 'organizationalUnit'])),
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

        $employee->load(['user', 'organizationalUnit', 'employeeQualifications.qualification', 'documents']);

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

        $employee->update($validated);

        // Observer will handle status transitions (e.g., pre_contract → active)

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();
        $freshEmployee->load(['user', 'organizationalUnit']);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
    }

    /**
     * Remove the specified employee (soft delete).
     *
     * DELETE /api/v1/employees/{employee}
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
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
                'message' => __('Cannot activate: onboarding must be completed and contract start date must have passed'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $employee->update(['status' => Employee::STATUS_ACTIVE]);

        // Observer will handle user account activation and role assignment

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();
        $freshEmployee->load(['user']);

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

        $employee->update(['status' => Employee::STATUS_TERMINATED]);

        // Observer will handle user account deactivation

        /** @var Employee $freshEmployee */
        $freshEmployee = $employee->fresh();
        $freshEmployee->load(['user']);

        return response()->json([
            'data' => new EmployeeResource($freshEmployee),
        ]);
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
        $latestEmployee = Employee::where('tenant_id', $tenantId)
            ->where('employee_number', 'like', "{$prefix}%")
            ->orderBy('employee_number', 'desc')
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
}
