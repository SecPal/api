<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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

        // Global activities (no organizational unit): Allow if permission granted
        if ($activity->organizational_unit_id === null) {
            return true;
        }

        // Organizational scope check: User must have access to activity's org unit
        $scopes = $user->organizationalScopes()
            ->where('organizational_unit_id', $activity->organizational_unit_id)
            ->get();

        if ($scopes->isEmpty()) {
            return false; // No scope for this organizational unit
        }

        // Leadership level filtering (NEW - Issue #396)
        // Only applies if activity was caused by a User
        if ($activity->causer_type === User::class && $activity->causer_id !== null) {
            // Find causer's employee record with leadership level (single query with join)
            $causerEmployee = Employee::where('user_id', $activity->causer_id)
                ->where('organizational_unit_id', $activity->organizational_unit_id)
                ->with('leadershipLevel')
                ->first();

            // If employee not found, the user may not exist or may not be an employee in this org unit

            if ($causerEmployee === null) {
                return false; // Causer has no employee record in this org unit
            }

            $causerRank = $causerEmployee->leadershipLevel?->rank;

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

    /**
     * Check if causer's rank is within user's viewable rank range.
     *
     * CRITICAL SEMANTICS (ADR-009):
     * - Guards have leadership_level_id = NULL (no rank)
     * - Leadership ranks: 1-255 (FE1 to FE255)
     * - Rank 0 is used in scopes to represent "Guards access"
     *
     * BEST PRACTICE: Use separate scopes for Guards vs Leadership
     * - Scope 1: min=0, max=0 → Guards access
     * - Scope 2: min=X, max=Y → Leadership ranks X to Y
     *
     * Rank Range Interpretations (single scope):
     * - min=0, max=0 → Only Guards visible
     * - min=1, max=Y → Leadership ranks 1 to Y (Guards NOT visible)
     * - min=X, max=255 → Leadership from rank X to 255
     * - min=null, max=null → All leadership ranks (Guards still need explicit min=0)
     * - min=0, max=X → Guards + Leadership up to rank X (possible but NOT recommended - use separate scopes)
     *
     * @param  int|null  $causerRank  Causer's leadership rank (NULL for Guards, 1-255 for FE1-FE255)
     * @param  int|null  $minViewableRank  Minimum viewable rank (0 = includes Guards, 1-255 for FE1-FE255, null = no lower bound)
     * @param  int|null  $maxViewableRank  Maximum viewable rank (0 = Guards only, 1-255 for FE1-FE255, null = no upper bound)
     */
    private function isWithinViewableRankRange(?int $causerRank, ?int $minViewableRank, ?int $maxViewableRank): bool
    {
        // Case 1: Causer is Guard (no leadership level, rank = NULL)
        if ($causerRank === null) {
            // Guards visible if scope includes rank 0 (min=0)
            return $minViewableRank === 0;
        }

        // Case 2: Causer has leadership level (rank 1-255)
        // Guards-only scope (min=0, max=0) cannot see leadership
        if ($maxViewableRank === 0) {
            return false; // This scope is for Guards only
        }

        // Check if leadership rank within specified range
        // NOTE: NULL semantics for min/max:
        // - minViewableRank=null means no lower bound (all leadership ranks >= 1 allowed)
        // - maxViewableRank=null means no upper bound (all leadership ranks <= 255 allowed)
        // - Both null = unrestricted leadership access (but guards still require explicit min=0)
        if ($minViewableRank !== null && $causerRank < $minViewableRank) {
            return false; // Below minimum
        }

        if ($maxViewableRank !== null && $causerRank > $maxViewableRank) {
            return false; // Above maximum
        }

        return true; // Within range
    }
}
