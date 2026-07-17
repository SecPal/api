<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Policies;

use App\Models\Activity;
use App\Models\Employee;
use App\Models\User;

/**
 * Activity Policy
 *
 * Authorization rules for activity log access with leadership-based filtering.
 *
 * CRITICAL: This policy integrates with Epic #399 (Leadership Levels System)
 * to enforce hierarchical access control for activity logs.
 *
 * Authorization Logic (Issue #396):
 * 1. Tenant isolation (mandatory for all activities)
 * 2. Permission check (activity_log.read required)
 * 3. Organizational scope check (must have access to activity's org unit)
 * 4. Leadership level filtering (can only view logs from subordinates)
 *
 * Rules:
 * - viewAny: Requires activity_log.read permission
 * - view: Tenant isolation + scope-based + leadership level filtering
 *
 * @see https://github.com/SecPal/api/issues/396 Issue #396: ActivityPolicy Integration
 * @see https://github.com/SecPal/api/issues/385 Epic #385: Activity Logging & Audit Trail
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-activity-logging-audit-trail-strategy.md ADR-010: Activity Logging Strategy
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md ADR-009: Leadership-Based Access Control
 */
class ActivityPolicy
{
    /**
     * Determine if user can view any activity logs.
     *
     * Users with activity_log.read permission can view activity logs.
     * Scope-based filtering handled at controller level.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('activity_log.read');
    }

    /**
     * Determine if user can view a specific activity log.
     *
     * AUTHORIZATION LOGIC (ADR-010 + ADR-009):
     * 1. Tenant isolation check (always first)
     * 2. Permission check: Requires activity_log.read permission
     * 3. Global activities (no org unit): Allowed if permission granted
     * 4. Organizational scope check: User must have scope for activity's org unit
     * 5. Leadership level filtering: User can only see logs from subordinates (or system)
     *
     * CRITICAL DESIGN DECISION (Issue #396):
     * Leadership filtering applies to the CAUSER's rank, not the SUBJECT's rank.
     * - If activity was caused by a User, check their employee's leadership level
     * - If activity has no causer (system) OR non-User causer, allow (no rank filtering)
     * - User must have organizational scope with appropriate rank range
     */
    public function view(User $user, Activity $activity): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $activity->tenant_id) {
            return false;
        }

        // Permission check
        if (! $user->can('activity_log.read')) {
            return false;
        }

        if (
            $this->isPrivilegedUserActivity($activity)
            && $activity->causer_id !== $user->id
            && ! $user->can('activity_log.read_system')
        ) {
            return false;
        }

        // Global activities (no organizational unit): Allow if permission granted
        if ($activity->organizational_unit_id === null) {
            return true;
        }

        // Organizational scope check: User must have access to activity's org unit
        $scopes = $user->organizationalScopes()
            ->where('organizational_unit_id', $activity->organizational_unit_id)
            ->whereIn('access_level', ['read', 'write', 'manage'])
            ->get();

        if ($scopes->isEmpty()) {
            return false; // No scope for this organizational unit
        }

        // Leadership level filtering (NEW - Issue #396)
        // Only applies if activity was caused by a User
        if ($activity->causer_type === User::class && $activity->causer_id !== null) {
            // CRITICAL: Users can always view their OWN activities
            // This is essential for:
            // - Login events (authentication log)
            // - Privileged/system users without Employee records
            // - Self-service transparency
            if ($activity->causer_id === $user->id) {
                return true; // User can view their own activities
            }

            $causerEmployee = Employee::where('user_id', $activity->causer_id)->first();

            // If employee not found in THIS org unit, check if they have an employee record ANYWHERE
            if ($causerEmployee === null) {
                $preservedCauserRank = $this->preservedCauserRankForActivity($activity);

                if ($preservedCauserRank !== null) {
                    foreach ($scopes as $scope) {
                        if ($this->isWithinViewableRankRange($preservedCauserRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
                            return true;
                        }
                    }

                    return false;
                }

                // Causer has NO employee record at all (privileged/system user)
                // System user activities require special permission for security
                if (! $user->can('activity_log.read_system')) {
                    return false;
                }

                // Allow viewing if user has scope for this org unit AND read_system permission
                return true;
            }

            $causerRank = $causerEmployee->management_level;

            // Check if causer's rank is within user's viewable range in ANY scope
            foreach ($scopes as $scope) {
                if ($this->isWithinViewableRankRange($causerRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
                    return true; // Causer visible in at least one scope
                }
            }

            return false; // Causer not visible in any scope
        }

        // Activity without User causer (system-generated or non-User causer)
        // Allow if user has scope for the organizational unit
        return true;
    }

    private function preservedCauserRankForActivity(Activity $activity): ?int
    {
        if ($activity->causer_employee_organizational_unit_id !== $activity->organizational_unit_id) {
            return null;
        }

        if ($activity->causer_employee_management_level === null || $activity->causer_employee_management_level < 0) {
            return null;
        }

        return $activity->causer_employee_management_level;
    }

    private function isPrivilegedUserActivity(Activity $activity): bool
    {
        if ($activity->causer_type !== User::class || $activity->causer_id === null) {
            return false;
        }

        if ($activity->causer_employee_id !== null) {
            return false;
        }

        return ! Employee::where('user_id', $activity->causer_id)->exists();
    }

    /**
     * Check if causer's management level is within user's viewable range.
     *
     * CRITICAL SEMANTICS (ADR-009):
     * - Non-management employees have management_level = 0
     * - Management levels: 1-255 (1=CEO/highest, 255=lowest)
     *
     * Two separate scope systems (cannot be mixed):
     * - 0/0: ONLY non-management employees (Guards)
     * - 1-255: Management levels (e.g., 1/5 = ML1-ML5, 1/255 = all management)
     * - Invalid: 0/5 (cannot mix non-management with management levels)
     *
     * @param  int  $causerLevel  Causer's management level (0=non-management, 1-255=management)
     * @param  int|null  $minViewableLevel  Minimum viewable level (0=non-management only, 1-255=management, null=no lower bound)
     * @param  int|null  $maxViewableLevel  Maximum viewable level (0=non-management only, 1-255=management, null=no upper bound)
     */
    private function isWithinViewableRankRange(int $causerLevel, ?int $minViewableLevel, ?int $maxViewableLevel): bool
    {
        // Case 1: Scope 0/0 = ONLY non-management employees
        if ($maxViewableLevel === 0) {
            return $causerLevel === 0; // Only non-management visible
        }

        // Case 2: Causer is non-management (level = 0)
        if ($causerLevel === 0) {
            return false; // Non-management not visible in management scopes (1-255)
        }

        // Case 3: Management level scopes (1-255)
        // Check if management level within specified range
        // NOTE: NULL semantics for min/max:
        // - minViewableLevel=null means no lower bound (all management levels >= 1 allowed)
        // - maxViewableLevel=null means no upper bound (all management levels <= 255 allowed)
        // - Both null = unrestricted management access (but non-management still require explicit 0/0 scope)
        if ($minViewableLevel !== null && $causerLevel < $minViewableLevel) {
            return false; // Below minimum
        }

        if ($maxViewableLevel !== null && $causerLevel > $maxViewableLevel) {
            return false; // Above maximum
        }

        return true; // Within range
    }
}
