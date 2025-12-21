<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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
     */
    public function logLoginSuccess(User $user): ?Activity
    {
        return activity('authentication')
            ->causedBy($user)
            ->withProperties([
                'event' => 'login_success',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->log('User logged in successfully');
    }

    /**
     * Log failed authentication attempt.
     *
     * Security Level: 2 (authentication)
     */
    public function logLoginFailed(string $email, string $reason): ?Activity
    {
        return activity('authentication')
            ->withProperties([
                'event' => 'login_failed',
                'email' => $email,
                'reason' => $reason,
            ])
            ->log('Failed login attempt');
    }

    /**
     * Log user logout.
     *
     * Security Level: 2 (authentication)
     */
    public function logLogout(User $user): ?Activity
    {
        return activity('authentication')
            ->causedBy($user)
            ->withProperties([
                'event' => 'logout',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ])
            ->log('User logged out');
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
