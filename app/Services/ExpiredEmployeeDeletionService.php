<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingSubmissionFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpiredEmployeeDeletionService
{
    public function __construct(private readonly UserDeviceAccessCleanupService $userDeviceAccessCleanupService) {}

    /**
     * @return array{matched: int, deleted: int, users_anonymized: int, files_deleted: int}
     */
    public function deleteExpiredEmployees(?int $tenantId = null, bool $dryRun = false): array
    {
        $query = $this->expiredEmployeesQuery($tenantId);

        $stats = [
            'matched' => (clone $query)->count(),
            'deleted' => 0,
            'users_anonymized' => 0,
            'files_deleted' => 0,
        ];

        if ($dryRun || $stats['matched'] === 0) {
            return $stats;
        }

        $query
            ->orderBy('id')
            ->chunkById(50, function ($employees) use (&$stats): void {
                foreach ($employees as $employee) {
                    $result = $this->deleteExpiredEmployee($employee->id, $employee->tenant_id);

                    $stats['deleted'] += $result['deleted'];
                    $stats['users_anonymized'] += $result['users_anonymized'];
                    $stats['files_deleted'] += $this->deleteStoredPaths($result['file_paths']);
                }
            }, 'id');

        return $stats;
    }

    /**
     * @return Builder<Employee>
     */
    public function expiredEmployeesQuery(?int $tenantId = null): Builder
    {
        return Employee::query()
            ->withTrashed()
            ->where('status', Employee::STATUS_TERMINATED)
            ->whereNotNull('retention_period_end')
            ->whereDate('retention_period_end', '<', Carbon::today()->toDateString())
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId));
    }

    /**
     * @return array{deleted: int, users_anonymized: int, file_paths: list<string>}
     */
    private function deleteExpiredEmployee(string $employeeId, int $tenantId): array
    {
        return DB::transaction(function () use ($employeeId, $tenantId): array {
            /** @var Employee|null $employee */
            $employee = Employee::query()
                ->withTrashed()
                ->with('user.passkeyCredentials')
                ->where('tenant_id', $tenantId)
                ->whereKey($employeeId)
                ->lockForUpdate()
                ->first();

            if (! $employee instanceof Employee || ! $this->isEligibleForDeletion($employee)) {
                return [
                    'deleted' => 0,
                    'users_anonymized' => 0,
                    'file_paths' => [],
                ];
            }

            $filePaths = $this->collectStoredPaths($employee);
            $usersAnonymized = 0;

            if ($employee->user instanceof User) {
                $this->anonymizeLinkedUser($employee->user);
                $employee->user()->dissociate();
                $employee->saveQuietly();
                $usersAnonymized = 1;
            }

            activity('employee_changes')
                ->performedOn($employee)
                ->withProperties([
                    'action' => 'employee_retention_delete',
                    'employee_id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'tenant_id' => $employee->tenant_id,
                    'employment_end_date' => $employee->employment_end_date?->toDateString(),
                    'retention_period_end' => $employee->retention_period_end?->toDateString(),
                    'legal_basis' => 'BewachV § 21 Abs. 4 / GDPR Art. 17',
                    'deleted_file_count' => count($filePaths),
                    'linked_user_anonymized' => $usersAnonymized === 1,
                ])
                ->log('Employee data deleted after retention period');

            $employee->forceDelete();

            return [
                'deleted' => 1,
                'users_anonymized' => $usersAnonymized,
                'file_paths' => $filePaths,
            ];
        });
    }

    private function isEligibleForDeletion(Employee $employee): bool
    {
        return $employee->status === Employee::STATUS_TERMINATED
            && $employee->retention_period_end !== null
            && $employee->retention_period_end->isBefore(Carbon::today());
    }

    /**
     * @return list<string>
     */
    private function collectStoredPaths(Employee $employee): array
    {
        $paths = [];

        if (is_string($employee->id_document_copy_path) && $employee->id_document_copy_path !== '') {
            $paths[] = $employee->id_document_copy_path;
        }

        $documentPaths = EmployeeDocument::query()
            ->withTrashed()
            ->where('employee_id', $employee->id)
            ->pluck('file_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->all();

        $submissionIds = OnboardingFormSubmission::query()
            ->withTrashed()
            ->where('employee_id', $employee->id)
            ->pluck('id');

        $submissionFilePaths = OnboardingSubmissionFile::query()
            ->withTrashed()
            ->whereIn('onboarding_form_submission_id', $submissionIds)
            ->pluck('file_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->all();

        $paths = [...$paths, ...$documentPaths, ...$submissionFilePaths];

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteStoredPaths(array $paths): int
    {
        $storage = Storage::disk('local');
        $deletedFiles = 0;

        foreach ($paths as $path) {
            $safePath = $this->validateEmployeeStoragePath($path);

            if ($safePath === null) {
                Log::warning('Refused to delete suspicious employee retention file path from local storage', [
                    'file_path' => $path,
                ]);

                continue;
            }

            if (! $storage->exists($safePath)) {
                continue;
            }

            if ($storage->delete($safePath)) {
                $deletedFiles++;

                continue;
            }

            Log::warning('Failed to delete expired employee retention file from local storage', [
                'file_path' => $safePath,
            ]);
        }

        return $deletedFiles;
    }

    private function validateEmployeeStoragePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', ltrim(trim($path), '/'));

        if ($normalized === '') {
            return null;
        }

        // Reject absolute paths (Unix or Windows drive-letter)
        if (str_starts_with($path, '/') || (strlen($path) >= 3 && $path[1] === ':')) {
            return null;
        }

        // Reject directory traversal
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        // Enforce employees/ prefix allowlist
        if (! str_starts_with($normalized, 'employees/')) {
            return null;
        }

        return $normalized;
    }

    private function anonymizeLinkedUser(User $user): void
    {
        $this->userDeviceAccessCleanupService->revokePendingAndroidEnrollmentSessionsAndDeletePushRegistrations(
            $user,
            'User account anonymized during employee retention deletion.',
        );

        $raw = config('permission.table_names.model_has_roles', 'model_has_roles');
        $modelHasRolesTable = is_string($raw) ? $raw : 'model_has_roles';
        $raw = config('permission.table_names.model_has_permissions', 'model_has_permissions');
        $modelHasPermissionsTable = is_string($raw) ? $raw : 'model_has_permissions';
        $raw = config('permission.column_names.model_morph_key', 'model_id');
        $modelMorphKey = is_string($raw) ? $raw : 'model_id';

        DB::table($modelHasRolesTable)
            ->where('model_type', User::class)
            ->where($modelMorphKey, $user->id)
            ->delete();

        DB::table($modelHasPermissionsTable)
            ->where('model_type', User::class)
            ->where($modelMorphKey, $user->id)
            ->delete();

        DB::table('user_internal_organizational_scopes')
            ->where('user_id', $user->id)
            ->delete();

        DB::table('customer_assignments')
            ->where('user_id', $user->id)
            ->delete();

        DB::table('site_assignments')
            ->where('user_id', $user->id)
            ->delete();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        DB::table('two_factor_authentications')
            ->where('authenticatable_type', User::class)
            ->where('authenticatable_id', $user->id)
            ->delete();

        $user->tokens()->delete();
        $user->passkeyCredentials()->delete();

        // Clear password reset tokens for the current (pre-anonymization) email to prevent
        // token reuse if the address is later registered again.
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();

        $user->forceFill([
            'name' => 'Deleted User',
            'email' => 'deleted-user+'.$user->id.'@secpal.dev',
            'email_verified_at' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'preferred_locale' => null,
        ])->save();
    }
}
