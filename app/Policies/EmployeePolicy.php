<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Support\Collection;

/**
 * Employee Policy
 *
 * Authorization rules for employee management with scope-based access control.
 *
 * Rules:
 * - viewAny: HR role OR Manager (within scope)
 * - view: Own profile OR HR OR Manager (scope-based)
 * - create: HR only
 * - update: Own profile (limited fields) OR HR
 * - delete: HR only (soft delete)
 * - activate: HR only (change status to active)
 * - placeOnLeave: HR only (change status to on_leave)
 * - returnFromLeave: HR only (restore status to active)
 * - terminate: HR only (change status to terminated)
 */
class EmployeePolicy
{
    /**
     * Determine if user can view any employees.
     *
     * Users with employee.read permission can view employees.
     * Scope-based filtering handled at controller level for managers.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('employee.read');
    }

    /**
     * Determine if user can view a specific employee.
     *
     * AUTHORIZATION LOGIC (ADR-009):
     * 1. Tenant isolation check (always first)
     * 2. Self-access: User can view own profile IF allow_self_access=true in scope
     * 3. Permission check: Requires employee.read permission
     * 4. Organizational scope check: ALL users MUST have scopes to access organizational data
     * 5. Rank filtering: Employee must be within user's viewable rank range
     *
     * IMPORTANT: There is NO role-based bypass without scopes - all users accessing
     * organizational features must have defined scopes (with appropriate rank ranges).
     */
    public function view(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.read'],
            'read',
            requireAssignableRank: false,
        );
    }

    /**
     * Determine if user can create employees.
     *
     * Users with employee.write or employee.create permission can create employees.
     */
    public function create(User $user): bool
    {
        return $user->can('employee.write') || $user->can('employee.create');
    }

    /**
     * Determine if user can update an employee.
     *
     * Employee can update own profile (limited fields) if allow_self_access = true.
     * Users with employee.update permission can update all employees.
     * Leadership Level filtering: Users with scopes can only edit employees within their viewable rank range.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.write', 'employee.update'],
            'write',
            requireAssignableRank: true,
        );
    }

    /**
     * Determine whether the user may create an employee in the given unit with the given management level.
     */
    public function canCreateInUnit(User $user, OrganizationalUnit $organizationalUnit, int $managementLevel): bool
    {
        if (! $this->create($user)) {
            return false;
        }

        return $this->scopesAuthorizeManagementLevel(
            $this->applicableScopes($user, $organizationalUnit, 'write'),
            $managementLevel,
            requireAssignableRank: true,
        );
    }

    /**
     * Determine whether the user may update an employee into the given unit and management level.
     */
    public function canUpdateInUnit(User $user, OrganizationalUnit $organizationalUnit, int $managementLevel): bool
    {
        if (! $user->can('employee.write') && ! $user->can('employee.update')) {
            return false;
        }

        return $this->scopesAuthorizeManagementLevel(
            $this->applicableScopes($user, $organizationalUnit, 'write'),
            $managementLevel,
            requireAssignableRank: true,
        );
    }

    /**
     * @return Collection<int, UserInternalOrganizationalScope>
     */
    private function applicableScopes(User $user, ?OrganizationalUnit $organizationalUnit, string $minimumAccessLevel): Collection
    {
        if ($organizationalUnit === null) {
            /** @var Collection<int, UserInternalOrganizationalScope> $emptyScopes */
            $emptyScopes = collect();

            return $emptyScopes;
        }

        /** @var Collection<int, UserInternalOrganizationalScope> $scopes */
        $scopes = $user->getApplicableOrganizationalScopesForUnit($organizationalUnit)
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel($minimumAccessLevel))
            ->values();

        return $scopes;
    }

    /**
     * Determine if user can delete an employee.
     *
     * Users with employee.write or employee.delete permission can delete employees (soft delete).
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.write', 'employee.delete'],
            'write',
            requireAssignableRank: true,
        );
    }

    /**
     * Determine if user can activate an employee.
     *
     * Users with employee.write or employee.activate permission can activate employees (transition to active status).
     */
    public function activate(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.write', 'employee.activate'],
            'write',
            requireAssignableRank: true,
        );
    }

    /**
     * Determine if user can confirm a pre-contract onboarding dossier.
     */
    public function confirmOnboarding(User $user, Employee $employee): bool
    {
        if ($user->tenant_id !== $employee->tenant_id) {
            return false;
        }

        return $user->can('onboarding.confirm');
    }

    /**
     * Determine if user can place an employee on leave.
     */
    public function placeOnLeave(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.write'],
            'write',
            requireAssignableRank: true,
        );
    }

    /**
     * Determine if user can restore an employee from leave.
     */
    public function returnFromLeave(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.write'],
            'write',
            requireAssignableRank: true,
        );
    }

    /**
     * Determine if user can terminate an employee.
     *
     * Users with employee.write or employee.terminate permission can terminate employees (transition to terminated status).
     */
    public function terminate(User $user, Employee $employee): bool
    {
        return $this->authorizeEmployeeAccess(
            $user,
            $employee,
            ['employee.write', 'employee.terminate'],
            'write',
            requireAssignableRank: true,
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authorizeEmployeeAccess(
        User $user,
        Employee $employee,
        array $permissions,
        string $minimumAccessLevel,
        bool $requireAssignableRank,
    ): bool {
        if ($user->tenant_id !== $employee->tenant_id || ! $this->userHasAnyPermission($user, $permissions)) {
            return false;
        }

        $scopes = $this->applicableScopes($user, $employee->organizationalUnit, $minimumAccessLevel);

        if ($scopes->isEmpty()) {
            return false;
        }

        if ($user->id === $employee->user_id) {
            return $scopes->contains(fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access);
        }

        return $this->scopesAuthorizeManagementLevel($scopes, $employee->management_level, $requireAssignableRank);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userHasAnyPermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $scopes
     */
    private function scopesAuthorizeManagementLevel(Collection $scopes, int $managementLevel, bool $requireAssignableRank): bool
    {
        if ($scopes->isEmpty()) {
            return false;
        }

        return $scopes->contains(function (UserInternalOrganizationalScope $scope) use ($managementLevel, $requireAssignableRank): bool {
            if (! $scope->canViewManagementLevel($managementLevel)) {
                return false;
            }

            return ! $requireAssignableRank || $scope->canAssignManagementLevel($managementLevel);
        });
    }
}
