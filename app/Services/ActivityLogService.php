<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Centralized service for manual activity logging.
 *
 * Provides consistent logging for scenarios where automatic model logging
 * via LogsActivity trait is not sufficient:
 * - Authentication events (login, logout, failed attempts)
 * - RBAC changes (role/permission assignments)
 * - HR data access (salary, contract access with reason)
 * - Manual event logging
 *
 * The Activity model's booted() hook automatically injects tenant_id,
 * organizational_unit_id, IP, and user_agent, so this service focuses
 * on providing domain-specific logging methods.
 */
class ActivityLogService
{
    /**
     * Log successful authentication.
     *
     * Security Level: 2 (authentication)
     * Logs to user's primary organizational unit for scope-based visibility.
     */
    public function logLoginSuccess(User $user): ?Activity
    {
        // Get user's primary organizational unit (first scope's org unit)
        $user->loadMissing('organizationalScopes');
        /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
        $firstScope = $user->organizationalScopes->first();
        $primaryOrgUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;

        return activity('authentication')
            ->causedBy($user)
            ->performedOn($user)
            ->useLog('authentication')
            ->withProperties([
                'event' => 'login_success',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->tap(function ($activity) use ($primaryOrgUnitId) {
                /** @var \App\Models\Activity $activity */
                $activity->organizational_unit_id = $primaryOrgUnitId;
            })
            ->log('User logged in successfully');
    }

    /**
     * Log failed authentication attempt.
     *
     * Security Level: 2 (authentication)
     *
     * Logs to organizational unit based on email:
     * - If email belongs to an employee: use employee's organizational_unit_id
     * - If email belongs to a user: use user's primary organizational_unit_id
     * - If email unknown: organizational_unit_id = NULL (global, only visible to users without organizational scopes who can review tenant-wide activity)
     *
     * Properties logged:
     * - user_exists: true if a User account exists for this email
     * - employee_exists: true if an Employee record exists for this email
     * - has_organizational_unit: true if an OU could be determined
     *
     * Possible combinations:
     * 1. user_exists=true, employee_exists=true, has_ou=true: User with employee record
     * 2. user_exists=true, employee_exists=false, has_ou=true: User without employee, but with OU scope
     * 3. user_exists=false, employee_exists=true, has_ou=true: Employee without user account
     * 4. user_exists=false, employee_exists=false, has_ou=false: Unknown email (no user, no employee)
     *
     * All cases are logged, including completely unknown emails for security auditing.
     */
    public function logLoginFailed(string $email, string $reason): ?Activity
    {
        // Try to find user by email to get their tenant_id
        $user = User::where('email', $email)->first();

        // Determine tenant_id from server-side state only.
        if ($user !== null) {
            $targetTenantId = $user->tenant_id;
        } else {
            // Fallback: use lowest-id tenant key to ensure deterministic attribution
            $firstTenant = \App\Models\TenantKey::orderBy('id')->first();
            if ($firstTenant === null) {
                // No tenant exists - skip activity logging (e.g. in tests without tenant setup)
                return null;
            }
            $targetTenantId = $firstTenant->id;
        }

        // Determine organizational_unit_id based on email
        $organizationalUnitId = null;
        $employee = null;

        if ($user) {
            // User exists - check if they have an employee record
            $employee = \App\Models\Employee::where('user_id', $user->id)->first();

            if ($employee instanceof \App\Models\Employee) {
                // Use employee's organizational unit
                $organizationalUnitId = $employee->organizational_unit_id;
            } else {
                // Use user's primary organizational unit (first scope)
                $user->loadMissing('organizationalScopes');
                /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
                $firstScope = $user->organizationalScopes->first();
                $organizationalUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;
            }
        } else {
            // User doesn't exist - check if email belongs to an employee (without user account)
            $employee = \App\Models\Employee::where('email', $email)->first();

            if ($employee instanceof \App\Models\Employee) {
                $organizationalUnitId = $employee->organizational_unit_id;
                $targetTenantId = $employee->tenant_id;
            }
            // else: unknown email (no user, no employee) - remains NULL (global, only visible to users without organizational scopes who can review tenant-wide activity)
        }

        // Create Activity manually to set tenant_id and organizational_unit_id BEFORE saving
        /** @var \App\Models\Activity $activity */
        $activity = \App\Models\Activity::create([
            'tenant_id' => $targetTenantId,
            'organizational_unit_id' => $organizationalUnitId,
            'log_name' => 'authentication',
            'description' => 'Failed login attempt',
            'properties' => [
                'event' => 'login_failed',
                'email' => $email,
                'reason' => $reason,
                'user_exists' => $user !== null,
                'employee_exists' => $employee !== null,
                'has_organizational_unit' => $organizationalUnitId !== null,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $activity;
    }

    /**
     * Log user logout.
     *
     * Security Level: 2 (authentication)
     * Logs to user's primary organizational unit for scope-based visibility.
     */
    public function logLogout(User $user): ?Activity
    {
        // Get user's primary organizational unit (first scope's org unit)
        $user->loadMissing('organizationalScopes');
        /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
        $firstScope = $user->organizationalScopes->first();
        $primaryOrgUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;

        return activity('authentication')
            ->causedBy($user)
            ->performedOn($user)
            ->useLog('authentication')
            ->withProperties([
                'event' => 'logout',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->tap(function ($activity) use ($primaryOrgUnitId) {
                /** @var \App\Models\Activity $activity */
                $activity->organizational_unit_id = $primaryOrgUnitId;
            })
            ->log('User logged out');
    }

    /**
     * Log user logout from all devices and sessions.
     *
     * Security Level: 2 (authentication)
     * Logs to user's primary organizational unit for scope-based visibility.
     */
    public function logLogoutAll(User $user): ?Activity
    {
        $user->loadMissing('organizationalScopes');
        /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
        $firstScope = $user->organizationalScopes->first();
        $primaryOrgUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;

        return activity('authentication')
            ->causedBy($user)
            ->performedOn($user)
            ->useLog('authentication')
            ->withProperties([
                'event' => 'logout_all',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->tap(function ($activity) use ($primaryOrgUnitId) {
                /** @var \App\Models\Activity $activity */
                $activity->organizational_unit_id = $primaryOrgUnitId;
            })
            ->log('User logged out from all devices');
    }

    /**
     * Log a successful password reset with active-session revocation.
     *
     * Security Level: 2 (authentication)
     * Logs to the user's primary organizational unit for scope-based visibility.
     */
    public function logPasswordReset(User $user): ?Activity
    {
        $user->loadMissing('organizationalScopes');
        /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
        $firstScope = $user->organizationalScopes->first();
        $primaryOrgUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;

        return activity('authentication')
            ->causedBy($user)
            ->performedOn($user)
            ->useLog('authentication')
            ->withProperties([
                'event' => 'password_reset',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->tap(function ($activity) use ($primaryOrgUnitId) {
                /** @var \App\Models\Activity $activity */
                $activity->organizational_unit_id = $primaryOrgUnitId;
            })
            ->log('User reset password and revoked active sessions');
    }

    /**
     * Log a self-service MFA lifecycle event for a user.
     *
     * Scoped to the user's primary organizational unit so visibility follows
     * the same org-scope / leadership rules as other authentication events.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logUserMfaEvent(User $user, string $event, string $description, array $properties = []): ?Activity
    {
        $user->loadMissing('organizationalScopes');
        /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
        $firstScope = $user->organizationalScopes->first();
        $primaryOrgUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;

        return activity('authentication')
            ->causedBy($user)
            ->performedOn($user)
            ->useLog('authentication')
            ->withProperties(array_merge([
                'event' => $event,
                'user_id' => $user->id,
                'user_email' => $user->email,
            ], $properties))
            ->tap(function ($activity) use ($primaryOrgUnitId) {
                /** @var \App\Models\Activity $activity */
                $activity->organizational_unit_id = $primaryOrgUnitId;
            })
            ->log($description);
    }

    /**
     * Log a privileged MFA reset performed for another user.
     *
     * Scoped to the target user's primary organizational unit so the reset
     * audit entry is subject to the same org-scope visibility rules as other
     * authentication events, preventing cross-OU exposure.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logPrivilegedMfaReset(User $user, User $targetUser, string $reason, array $properties = []): ?Activity
    {
        $targetUser->loadMissing('organizationalScopes');
        /** @var \App\Models\UserInternalOrganizationalScope|null $firstScope */
        $firstScope = $targetUser->organizationalScopes->first();
        $targetOrgUnitId = $firstScope !== null ? $firstScope->organizational_unit_id : null;

        return activity('authentication')
            ->causedBy($user)
            ->performedOn($targetUser)
            ->useLog('authentication')
            ->withProperties(array_merge([
                'event' => 'mfa_reset_by_privileged_user',
                'target_user_id' => $targetUser->id,
                'target_user_email' => $targetUser->email,
                'reason' => $reason,
            ], $properties))
            ->tap(function ($activity) use ($targetOrgUnitId) {
                /** @var \App\Models\Activity $activity */
                $activity->organizational_unit_id = $targetOrgUnitId;
            })
            ->log('Privileged user reset multi-factor authentication');
    }

    /**
     * Log role assignment to user.
     *
     * Security Level: 2 (rbac_changes)
     *
     * @param  array<string>  $roles
     */
    public function logRoleAssignment(User $user, User $targetUser, array $roles, ?string $reason = null): ?Activity
    {
        return activity('rbac_changes')
            ->causedBy($user)
            ->performedOn($targetUser)
            ->withProperties([
                'event' => 'role_assigned',
                'target_user_id' => $targetUser->id,
                'target_user_email' => $targetUser->email,
                'roles' => $roles,
                'reason' => $reason,
            ])
            ->log('Assigned roles to user');
    }

    /**
     * Log role revocation from user.
     *
     * Security Level: 2 (rbac_changes)
     *
     * @param  array<string>  $roles
     */
    public function logRoleRevocation(User $user, User $targetUser, array $roles, ?string $reason = null): ?Activity
    {
        return activity('rbac_changes')
            ->causedBy($user)
            ->performedOn($targetUser)
            ->withProperties([
                'event' => 'role_revoked',
                'target_user_id' => $targetUser->id,
                'target_user_email' => $targetUser->email,
                'roles' => $roles,
                'reason' => $reason,
            ])
            ->log('Revoked roles from user');
    }

    /**
     * Log permission change.
     *
     * Security Level: 2 (rbac_changes)
     *
     * @param  array<string>  $permissions
     */
    public function logPermissionChange(User $user, string $action, array $permissions, ?string $targetType = null, ?string $targetId = null): ?Activity
    {
        return activity('rbac_changes')
            ->causedBy($user)
            ->withProperties([
                'event' => 'permission_changed',
                'action' => $action, // 'granted' or 'revoked'
                'permissions' => $permissions,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ])
            ->log('Permission change');
    }

    /**
     * Log scope grant/revocation.
     *
     * Security Level: 2 (scope_changes)
     */
    public function logScopeChange(User $user, User $targetUser, string $scopeType, string $scopeId, string $action): ?Activity
    {
        return activity('scope_changes')
            ->causedBy($user)
            ->performedOn($targetUser)
            ->withProperties([
                'event' => 'scope_changed',
                'target_user_id' => $targetUser->id,
                'target_user_email' => $targetUser->email,
                'scope_type' => $scopeType, // 'site', 'organizational_unit'
                'scope_id' => $scopeId,
                'action' => $action, // 'granted' or 'revoked'
            ])
            ->log('Scope access changed');
    }

    /**
     * Log HR data access (salary, contract data).
     *
     * Security Level: 3 (hr_access)
     *
     * @param  array<string>  $accessedFields
     */
    public function logHRAccess(User $user, Model $subject, array $accessedFields, string $reason): ?Activity
    {
        return activity('hr_access')
            ->causedBy($user)
            ->performedOn($subject)
            ->withProperties([
                'event' => 'hr_data_accessed',
                'accessed_fields' => $accessedFields,
                'reason' => $reason,
            ])
            ->log('Accessed sensitive HR data');
    }

    /**
     * Log generic sensitive data access.
     *
     * Security Level: 3 (sensitive_access)
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logSensitiveAccess(User $user, Model $subject, string $dataType, string $reason, array $metadata = []): ?Activity
    {
        return activity('sensitive_access')
            ->causedBy($user)
            ->performedOn($subject)
            ->withProperties(array_merge([
                'event' => 'sensitive_data_accessed',
                'data_type' => $dataType,
                'reason' => $reason,
            ], $metadata))
            ->log('Accessed sensitive data');
    }

    /**
     * Log custom event with flexible properties.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logCustomEvent(
        string $logName,
        string $description,
        ?User $causer = null,
        ?Model $subject = null,
        array $properties = []
    ): ?Activity {
        $activity = activity($logName);

        if ($causer) {
            $activity->causedBy($causer);
        }

        if ($subject) {
            $activity->performedOn($subject);
        }

        if (! empty($properties)) {
            $activity->withProperties($properties);
        }

        return $activity->log($description);
    }
}
