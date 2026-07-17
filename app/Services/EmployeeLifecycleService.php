<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Services;

use App\Mail\AccountDeactivatedMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EmployeeLifecycleService
{
    private const EMPLOYEE_ROLE_NAME = 'Employee';

    private const ON_LEAVE_ROLE_NAME = 'Employee Read Only';

    public function __construct(private readonly ActivityCauserContextService $activityCauserContextService) {}

    /**
     * Activate a pre-contract employee and provision their runtime access atomically.
     */
    public function activate(Employee $employee): Employee
    {
        /** @var User|null $activatedUser */
        $activatedUser = null;

        $activatedEmployee = DB::transaction(function () use ($employee, &$activatedUser): Employee {
            $employee = $this->refreshEmployee($employee);

            $user = $employee->user;

            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot activate: employee has no linked user account'),
                ]);
            }

            if (! $employee->canActivate()) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot activate: onboarding must be completed, workflow must be ready for activation, and contract start date must have passed'),
                ]);
            }

            $this->ensureEstablishmentAcceptsActivation($employee);

            $role = $this->resolveRole(self::EMPLOYEE_ROLE_NAME);

            $employee->transitionOnboardingWorkflowTo(Employee::WORKFLOW_STATUS_ACTIVE);

            $employee->forceFill([
                'status' => Employee::STATUS_ACTIVE,
                'user_account_active' => true,
                'user_account_activated_at' => now(),
                'runtime_access_snapshot' => null,
            ])->save();

            $this->insertRoleAssignment($user, $employee->tenant_id, $role, [
                'valid_from' => $employee->contract_start_date,
                'valid_until' => $employee->termination_date,
                'auto_revoke' => true,
                'assigned_by' => null,
                'reason' => 'Automatic role assignment on contract start',
            ]);

            $activatedUser = $user;

            return $this->refreshEmployee($employee);
        });

        // Cache invalidation runs after the DB transaction commits to prevent a window
        // where a concurrent request caches the pre-commit state.
        if ($activatedUser instanceof User) {
            $this->refreshAuthorizationContext($activatedUser);
        }

        $this->queueWelcomeMail($activatedEmployee);

        return $activatedEmployee;
    }

    /**
     * Reduce runtime access to a read-only baseline while an employee is on leave.
     */
    public function placeOnLeave(Employee $employee): Employee
    {
        /** @var User|null $affectedUser */
        $affectedUser = null;

        $result = DB::transaction(function () use ($employee, &$affectedUser): Employee {
            $employee = $this->refreshEmployee($employee);

            if ($employee->status !== Employee::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot place on leave: employee must be active'),
                ]);
            }

            $user = $employee->user;

            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot place on leave: employee has no linked user account'),
                ]);
            }

            if ($employee->runtime_access_snapshot !== null) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot place on leave: unresolved runtime access snapshot already exists'),
                ]);
            }

            $readOnlyRole = $this->resolveRole(self::ON_LEAVE_ROLE_NAME);
            $snapshot = $this->snapshotRuntimeAccess($user, $employee->tenant_id);

            $this->clearRuntimeAccess($user, $employee->tenant_id);
            $this->insertRoleAssignment($user, $employee->tenant_id, $readOnlyRole, [
                'valid_from' => now(),
                'valid_until' => $employee->termination_date,
                'auto_revoke' => true,
                'assigned_by' => null,
                'reason' => 'Automatic read-only access while employee is on leave',
            ]);

            $employee->forceFill([
                'status' => Employee::STATUS_ON_LEAVE,
                'runtime_access_snapshot' => $snapshot,
            ])->save();

            $affectedUser = $user;

            return $this->refreshEmployee($employee);
        });

        // Cache invalidation runs after the DB transaction commits.
        if ($affectedUser instanceof User) {
            $this->refreshAuthorizationContext($affectedUser);
        }

        return $result;
    }

    /**
     * Restore the runtime access model that was active before the employee went on leave.
     */
    public function returnFromLeave(Employee $employee): Employee
    {
        /** @var User|null $affectedUser */
        $affectedUser = null;

        $result = DB::transaction(function () use ($employee, &$affectedUser): Employee {
            $employee = $this->refreshEmployee($employee);

            if ($employee->status !== Employee::STATUS_ON_LEAVE) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot return from leave: employee must currently be on leave'),
                ]);
            }

            $this->ensureEstablishmentAcceptsActivation($employee);

            $user = $employee->user;

            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot return from leave: employee has no linked user account'),
                ]);
            }

            $snapshot = $this->resolveRuntimeAccessSnapshot($employee);

            $this->clearRuntimeAccess($user, $employee->tenant_id);
            $this->restoreRuntimeAccess($user, $employee->tenant_id, $snapshot);

            $employee->forceFill([
                'status' => Employee::STATUS_ACTIVE,
                'runtime_access_snapshot' => null,
            ])->save();

            $affectedUser = $user;

            return $this->refreshEmployee($employee);
        });

        // Cache invalidation runs after the DB transaction commits.
        if ($affectedUser instanceof User) {
            $this->refreshAuthorizationContext($affectedUser);
        }

        return $result;
    }

    /**
     * Terminate an employee and revoke their runtime access atomically.
     */
    public function terminate(Employee $employee): Employee
    {
        /** @var User|null $terminatedUser */
        $terminatedUser = null;

        $terminatedEmployee = DB::transaction(function () use ($employee, &$terminatedUser): Employee {
            $employee = $this->refreshEmployee($employee);

            /** @var User|null $user */
            $user = $employee->user;

            $employee->forceFill([
                'status' => Employee::STATUS_TERMINATED,
                'user_account_active' => false,
                'user_account_deactivated_at' => now(),
                'runtime_access_snapshot' => null,
            ])->save();

            if ($user instanceof User) {
                $this->clearRuntimeAccess($user, $employee->tenant_id);
                $user->tokens()->delete();

                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->delete();

                $terminatedUser = $user;
            }

            return $this->refreshEmployee($employee);
        });

        // Cache invalidation runs after the DB transaction commits.
        if ($terminatedUser instanceof User) {
            $this->refreshAuthorizationContext($terminatedUser);
        }

        $this->queueDeactivationMail($terminatedEmployee);

        return $terminatedEmployee;
    }

    /**
     * Soft-delete an employee and permanently remove the linked runtime user account.
     */
    public function delete(Employee $employee): Employee
    {
        /** @var User|null $affectedUser */
        $affectedUser = null;

        $deletedEmployee = DB::transaction(function () use ($employee, &$affectedUser): Employee {
            $employee = $this->refreshEmployee($employee);

            /** @var User|null $user */
            $user = $employee->user;

            $employee->forceFill([
                'user_account_active' => false,
                'user_account_deactivated_at' => now(),
                'runtime_access_snapshot' => null,
            ])->save();

            if ($user instanceof User) {
                $this->activityCauserContextService->preserveForEmployee($employee, $user);
                $employee->user()->dissociate();
                $employee->saveQuietly();

                $hasOtherLiveEmployeeLinks = Employee::query()
                    ->where('user_id', $user->id)
                    ->whereKeyNot($employee->id)
                    ->where('user_account_active', true)
                    ->where('status', '!=', Employee::STATUS_TERMINATED)
                    ->exists();

                $hasOtherEmployeeLinks = Employee::withTrashed()
                    ->where('user_id', $user->id)
                    ->whereKeyNot($employee->id)
                    ->exists();

                if (! $hasOtherLiveEmployeeLinks) {
                    if ($hasOtherEmployeeLinks) {
                        $this->deprovisionUserAccount($user, $employee->tenant_id);
                    } else {
                        $this->deprovisionUserAccount($user, $employee->tenant_id, deleteUser: true);
                    }
                }

                $affectedUser = $user;
            }

            $employee->delete();

            return $this->refreshEmployee($employee);
        });

        if ($affectedUser instanceof User) {
            $this->refreshAuthorizationContext($affectedUser);
        }

        return $deletedEmployee;
    }

    private function resolveRole(string $roleName): Role
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();

        if (! $role instanceof Role) {
            throw new RuntimeException("Role \"{$roleName}\" not found.");
        }

        return $role;
    }

    /**
     * @param  array{valid_from: mixed, valid_until: mixed, auto_revoke: bool, assigned_by: mixed, reason: mixed}  $attributes
     */
    private function insertRoleAssignment(User $user, int $tenantId, Role $role, array $attributes): void
    {
        $assignmentExists = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->exists();

        if ($assignmentExists) {
            return;
        }

        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $tenantId,
            'valid_from' => $attributes['valid_from'],
            'valid_until' => $attributes['valid_until'],
            'auto_revoke' => $attributes['auto_revoke'],
            'assigned_by' => $attributes['assigned_by'],
            'reason' => $attributes['reason'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{
     *     roles: list<array{role_id: mixed, valid_from: mixed, valid_until: mixed, auto_revoke: bool, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>,
     *     permissions: list<array{permission_id: mixed, valid_from: mixed, valid_until: mixed, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>
     * }
     */
    private function snapshotRuntimeAccess(User $user, int $tenantId): array
    {
        /** @var list<array{role_id: mixed, valid_from: mixed, valid_until: mixed, auto_revoke: bool, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}> $roles */
        $roles = array_values(DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->get([
                'role_id',
                'valid_from',
                'valid_until',
                'auto_revoke',
                'assigned_by',
                'reason',
                'created_at',
                'updated_at',
            ])
            ->map(static fn (object $assignment): array => [
                'role_id' => $assignment->role_id,
                'valid_from' => $assignment->valid_from,
                'valid_until' => $assignment->valid_until,
                'auto_revoke' => (bool) $assignment->auto_revoke,
                'assigned_by' => $assignment->assigned_by,
                'reason' => $assignment->reason,
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ])
            ->all());

        /** @var list<array{permission_id: mixed, valid_from: mixed, valid_until: mixed, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}> $permissions */
        $permissions = array_values(DB::table('model_has_permissions')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->get([
                'permission_id',
                'valid_from',
                'valid_until',
                'assigned_by',
                'reason',
                'created_at',
                'updated_at',
            ])
            ->map(static fn (object $assignment): array => [
                'permission_id' => $assignment->permission_id,
                'valid_from' => $assignment->valid_from,
                'valid_until' => $assignment->valid_until,
                'assigned_by' => $assignment->assigned_by,
                'reason' => $assignment->reason,
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ])
            ->all());

        return [
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }

    /**
     * @return array{
     *     roles: list<array{role_id: mixed, valid_from: mixed, valid_until: mixed, auto_revoke: bool, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>,
     *     permissions: list<array{permission_id: mixed, valid_from: mixed, valid_until: mixed, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>
     * }
     */
    private function resolveRuntimeAccessSnapshot(Employee $employee): array
    {
        $snapshot = $employee->runtime_access_snapshot;

        if (! is_array($snapshot)) {
            throw ValidationException::withMessages([
                'employee' => __('Cannot return from leave: employee has no runtime access snapshot to restore'),
            ]);
        }

        $roles = $snapshot['roles'] ?? null;
        $permissions = $snapshot['permissions'] ?? null;

        if (! is_array($roles) || ! is_array($permissions)) {
            throw ValidationException::withMessages([
                'employee' => __('Cannot return from leave: employee runtime access snapshot is invalid'),
            ]);
        }

        return [
            'roles' => $this->normalizeRoleSnapshotEntries($roles),
            'permissions' => $this->normalizePermissionSnapshotEntries($permissions),
        ];
    }

    private function clearRuntimeAccess(User $user, int $tenantId): void
    {
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->delete();

        DB::table('model_has_permissions')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    private function deprovisionUserAccount(User $user, int $tenantId, bool $deleteUser = false): void
    {
        $this->clearRuntimeAccess($user, $tenantId);

        DB::table('user_internal_organizational_scopes')
            ->where('user_id', $user->id)
            ->delete();

        $this->deactivateTemporalAssignments('customer_assignments', $user->id);
        $this->deactivateTemporalAssignments('site_assignments', $user->id);

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        DB::table('two_factor_authentications')
            ->where('authenticatable_type', User::class)
            ->where('authenticatable_id', $user->id)
            ->delete();

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();

        DB::table('push_device_registrations')
            ->where('user_id', $user->id)
            ->delete();

        $androidDeprovisionedAt = now();

        DB::table('android_enrollment_sessions')
            ->where('created_by', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('exchanged_at')
            ->where('bootstrap_token_expires_at', '>', $androidDeprovisionedAt)
            ->update([
                'created_by' => null,
                'revoked_at' => $androidDeprovisionedAt,
                'revocation_reason' => 'Enrollment session creator was deprovisioned.',
                'updated_at' => $androidDeprovisionedAt,
            ]);

        DB::table('android_enrollment_sessions')
            ->where('created_by', $user->id)
            ->update([
                'created_by' => null,
                'updated_at' => $androidDeprovisionedAt,
            ]);

        $user->tokens()->delete();
        $user->passkeyCredentials()->delete();

        if ($deleteUser) {
            $user->delete();

            return;
        }

        $user->forceFill([
            'name' => 'Deleted User',
            'email' => 'deleted-user+'.$user->id.'@secpal.dev',
            'email_verified_at' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'preferred_locale' => null,
        ])->save();
    }

    private function deactivateTemporalAssignments(string $table, string $userId): void
    {
        $deprovisionedUntil = now()->subDay()->toDateString();

        DB::table($table)
            ->where('user_id', $userId)
            ->where(function (Builder $query) use ($deprovisionedUntil): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $deprovisionedUntil);
            })
            ->get(['id', 'valid_from'])
            ->each(function (object $assignment) use ($table, $deprovisionedUntil): void {
                $validFrom = $assignment->valid_from;

                if ($validFrom !== null && $validFrom > $deprovisionedUntil) {
                    DB::table($table)
                        ->where('id', $assignment->id)
                        ->delete();

                    return;
                }

                DB::table($table)
                    ->where('id', $assignment->id)
                    ->update([
                        'valid_from' => $validFrom,
                        'valid_until' => $deprovisionedUntil,
                        'updated_at' => now(),
                    ]);
            });
    }

    /**
     * @param  array{
     *     roles: list<array{role_id: mixed, valid_from: mixed, valid_until: mixed, auto_revoke: bool, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>,
     *     permissions: list<array{permission_id: mixed, valid_from: mixed, valid_until: mixed, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>
     * }  $snapshot
     */
    private function restoreRuntimeAccess(User $user, int $tenantId, array $snapshot): void
    {
        $roleAssignments = collect($snapshot['roles'])
            ->map(fn (array $assignment): array => [
                'role_id' => $assignment['role_id'],
                'model_type' => User::class,
                'model_id' => $user->id,
                'tenant_id' => $tenantId,
                'valid_from' => $assignment['valid_from'] ?? null,
                'valid_until' => $assignment['valid_until'] ?? null,
                'auto_revoke' => $assignment['auto_revoke'],
                'assigned_by' => $assignment['assigned_by'] ?? null,
                'reason' => $assignment['reason'] ?? null,
                'created_at' => $assignment['created_at'] ?? now(),
                'updated_at' => $assignment['updated_at'] ?? now(),
            ])
            ->values()
            ->all();

        if ($roleAssignments !== []) {
            DB::table('model_has_roles')->insert($roleAssignments);
        }

        $permissionAssignments = collect($snapshot['permissions'])
            ->map(fn (array $assignment): array => [
                'permission_id' => $assignment['permission_id'],
                'model_type' => User::class,
                'model_id' => $user->id,
                'tenant_id' => $tenantId,
                'valid_from' => $assignment['valid_from'] ?? null,
                'valid_until' => $assignment['valid_until'] ?? null,
                'assigned_by' => $assignment['assigned_by'] ?? null,
                'reason' => $assignment['reason'] ?? null,
                'created_at' => $assignment['created_at'] ?? now(),
                'updated_at' => $assignment['updated_at'] ?? now(),
            ])
            ->values()
            ->all();

        if ($permissionAssignments !== []) {
            DB::table('model_has_permissions')->insert($permissionAssignments);
        }
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @return list<array{role_id: mixed, valid_from: mixed, valid_until: mixed, auto_revoke: bool, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>
     */
    private function normalizeRoleSnapshotEntries(array $entries): array
    {
        $normalized = [];

        foreach (array_values($entries) as $entry) {
            if (! is_array($entry) || ! array_key_exists('role_id', $entry)) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot return from leave: employee runtime role snapshot is invalid'),
                ]);
            }

            $normalized[] = [
                'role_id' => $entry['role_id'],
                'valid_from' => $entry['valid_from'] ?? null,
                'valid_until' => $entry['valid_until'] ?? null,
                'auto_revoke' => (bool) ($entry['auto_revoke'] ?? true),
                'assigned_by' => $entry['assigned_by'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'created_at' => $entry['created_at'] ?? null,
                'updated_at' => $entry['updated_at'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @return list<array{permission_id: mixed, valid_from: mixed, valid_until: mixed, assigned_by: mixed, reason: mixed, created_at: mixed, updated_at: mixed}>
     */
    private function normalizePermissionSnapshotEntries(array $entries): array
    {
        $normalized = [];

        foreach (array_values($entries) as $entry) {
            if (! is_array($entry) || ! array_key_exists('permission_id', $entry)) {
                throw ValidationException::withMessages([
                    'employee' => __('Cannot return from leave: employee runtime permission snapshot is invalid'),
                ]);
            }

            $normalized[] = [
                'permission_id' => $entry['permission_id'],
                'valid_from' => $entry['valid_from'] ?? null,
                'valid_until' => $entry['valid_until'] ?? null,
                'assigned_by' => $entry['assigned_by'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'created_at' => $entry['created_at'] ?? null,
                'updated_at' => $entry['updated_at'] ?? null,
            ];
        }

        return $normalized;
    }

    private function queueWelcomeMail(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            Mail::to($user->email)->queue(new WelcomeActiveMail($employee));
        } catch (\Throwable $throwable) {
            Log::error('User account activation mail dispatch failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function queueDeactivationMail(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            Mail::to($user->email)->queue(new AccountDeactivatedMail($employee));
        } catch (\Throwable $throwable) {
            Log::error('User account deactivation mail dispatch failed', [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function refreshEmployee(Employee $employee): Employee
    {
        /** @var Employee|null $refreshedEmployee */
        $refreshedEmployee = $employee->fresh();

        if (! $refreshedEmployee instanceof Employee) {
            $employee->load(['user']);

            return $employee;
        }

        $refreshedEmployee->load(['user']);

        return $refreshedEmployee;
    }

    private function ensureEstablishmentAcceptsActivation(Employee $employee): void
    {
        $establishment = Establishment::withTrashed()
            ->where('tenant_id', $employee->tenant_id)
            ->where('legal_entity_id', $employee->legal_entity_id)
            ->whereHas('legalEntity', fn (EloquentBuilder $query): EloquentBuilder => $query->where('is_active', true))
            ->find($employee->establishment_id);

        if ($establishment instanceof Establishment && ! $establishment->trashed() && $establishment->is_active) {
            return;
        }

        throw ValidationException::withMessages([
            'establishment_id' => __('The selected establishment is inactive or unavailable.'),
        ]);
    }

    private function refreshAuthorizationContext(User $user): void
    {
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
