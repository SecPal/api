<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeadershipLevel;
use App\Models\User;

/**
 * Authorization policy for LeadershipLevel model.
 *
 * CRITICAL ARCHITECTURAL DECISION (ADR-009):
 * This policy uses PURE permission-based authorization.
 * The user's OWN leadership level has ZERO influence on these checks.
 *
 * Leadership levels are NOT a permission system - they are a filtering mechanism.
 * Permission checks happen here. Leadership-based filtering happens elsewhere
 * (e.g., in Employee CRUD operations via max_assignable_rank).
 *
 * Operation 1 (Leadership Level Definition CRUD):
 * - viewAny: Check 'leadership_level.view' permission only
 * - view: Check 'leadership_level.view' permission only
 * - create: Check 'leadership_level.create' permission only
 * - update: Check 'leadership_level.update' permission only
 * - delete: Check 'leadership_level.delete' permission + no employees assigned
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md ADR-009: Leadership-Based Access Control
 */
class LeadershipLevelPolicy
{
    /**
     * Determine whether the user can view any leadership levels.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     *
     * @param  User  $user  The authenticated user
     * @return bool True if user has 'leadership_level.view' permission
     */
    public function viewAny(User $user): bool
    {
        return $user->can('leadership_level.view');
    }

    /**
     * Determine whether the user can view the leadership level.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     * Enforces tenant isolation: user can only view levels from their own tenant.
     *
     * @param  User  $user  The authenticated user
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to view
     * @return bool True if user has permission and level belongs to user's tenant
     */
    public function view(User $user, LeadershipLevel $leadershipLevel): bool
    {
        // Tenant isolation: user can only view levels from their own tenant
        if ($leadershipLevel->tenant_id !== $user->tenant_id) {
            return false;
        }

        return $user->can('leadership_level.view');
    }

    /**
     * Determine whether the user can create leadership levels.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     *
     * @param  User  $user  The authenticated user
     * @return bool True if user has 'leadership_level.create' permission
     */
    public function create(User $user): bool
    {
        return $user->can('leadership_level.create');
    }

    /**
     * Determine whether the user can update the leadership level.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     * Enforces tenant isolation: user can only update levels from their own tenant.
     *
     * @param  User  $user  The authenticated user
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to update
     * @return bool True if user has permission and level belongs to user's tenant
     */
    public function update(User $user, LeadershipLevel $leadershipLevel): bool
    {
        // Tenant isolation: user can only update levels from their own tenant
        if ($leadershipLevel->tenant_id !== $user->tenant_id) {
            return false;
        }

        return $user->can('leadership_level.update');
    }

    /**
     * Determine whether the user can delete the leadership level.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     * Enforces tenant isolation: user can only delete levels from their own tenant.
     * Business rule: Cannot delete if employees are assigned.
     *
     * @param  User  $user  The authenticated user
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to delete
     * @return bool True if user has permission, level belongs to user's tenant, and no employees assigned
     */
    public function delete(User $user, LeadershipLevel $leadershipLevel): bool
    {
        // Tenant isolation: user can only delete levels from their own tenant
        if ($leadershipLevel->tenant_id !== $user->tenant_id) {
            return false;
        }

        // Business rule: Cannot delete if employees are assigned
        if (! $leadershipLevel->canBeDeleted()) {
            return false;
        }

        return $user->can('leadership_level.delete');
    }

    /**
     * Determine whether the user can restore a soft-deleted leadership level.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     * Enforces tenant isolation: user can only restore levels from their own tenant.
     *
     * @param  User  $user  The authenticated user
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to restore
     * @return bool True if user has permission and level belongs to user's tenant
     */
    public function restore(User $user, LeadershipLevel $leadershipLevel): bool
    {
        // Tenant isolation: user can only restore levels from their own tenant
        if ($leadershipLevel->tenant_id !== $user->tenant_id) {
            return false;
        }

        return $user->can('leadership_level.update');
    }

    /**
     * Determine whether the user can permanently delete the leadership level.
     *
     * Pure permission check. User's own leadership level is irrelevant.
     * Force delete typically requires elevated permissions (e.g., admin).
     *
     * @param  User  $user  The authenticated user
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to force delete
     * @return bool True if user has 'leadership_level.delete' permission
     */
    public function forceDelete(User $user, LeadershipLevel $leadershipLevel): bool
    {
        return $user->can('leadership_level.delete');
    }
}
