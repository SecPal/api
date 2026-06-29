<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\OrganizationalUnit;
use App\Models\User;

/**
 * Authorization policy for OrganizationalUnit model.
 *
 * Controls access to organizational units based on the user's scope assignments
 * and hierarchical access levels. Uses the Closure Table pattern for efficient
 * ancestor/descendant checks.
 *
 * Access Level Hierarchy:
 * - none (0): No access
 * - read (1): Can view organizational unit details
 * - write (2): Can update organizational unit properties
 * - manage (3): Full control including deletion and scope management
 */
class OrganizationalUnitPolicy
{
    /**
     * Determine whether the user can view any organizational units.
     *
     * Requires at least one organizational scope assignment.
     */
    public function viewAny(User $user): bool
    {
        return $user->organizationalScopes()->exists();
    }

    /**
     * Determine whether the user can view the organizational unit.
     *
     * Requires at least 'read' access level on the unit or an ancestor.
     */
    public function view(User $user, OrganizationalUnit $unit): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $unit->tenant_id) {
            return false;
        }

        return $user->hasAccessToUnit($unit, 'read');
    }

    /**
     * Determine whether the user can create child organizational units.
     *
     * Requires at least 'manage' access level on the parent unit.
     *
     * @param  OrganizationalUnit  $parent  The parent unit under which to create
     */
    public function create(User $user, OrganizationalUnit $parent): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $parent->tenant_id) {
            return false;
        }

        return $user->hasAccessToUnit($parent, 'manage');
    }

    /**
     * Determine whether the user can update the organizational unit.
     *
     * Requires at least 'write' access level on the unit.
     */
    public function update(User $user, OrganizationalUnit $unit): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $unit->tenant_id) {
            return false;
        }

        return $user->hasAccessToUnit($unit, 'write');
    }

    /**
     * Determine whether the user can delete the organizational unit.
     *
     * Requires 'manage' access level on the unit.
     */
    public function delete(User $user, OrganizationalUnit $unit): bool
    {
        // CRITICAL: Tenant isolation check FIRST (defense-in-depth)
        if ($user->tenant_id !== $unit->tenant_id) {
            return false;
        }

        return $user->hasAccessToUnit($unit, 'manage');
    }

    /**
     * Determine whether the user can restore the organizational unit.
     *
     * Requires 'manage' access level on the unit.
     */
    public function restore(User $user, OrganizationalUnit $unit): bool
    {
        return $user->hasAccessToUnit($unit, 'manage');
    }

    /**
     * Determine whether the user can permanently delete the organizational unit.
     *
     * Requires 'manage' access level on the unit.
     */
    public function forceDelete(User $user, OrganizationalUnit $unit): bool
    {
        return $user->hasAccessToUnit($unit, 'manage');
    }

    /**
     * Determine whether the user can manage user scope assignments for the unit.
     *
     * This includes assigning/revoking access for other users to this unit.
     * Requires 'manage' access level on the unit.
     */
    public function manageScopes(User $user, OrganizationalUnit $unit): bool
    {
        if ($user->tenant_id !== $unit->tenant_id) {
            return false;
        }

        return $user->can('organizational_scopes.manage')
            && $user->hasAccessToUnit($unit, 'manage');
    }
}
