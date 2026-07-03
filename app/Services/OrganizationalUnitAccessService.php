<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;

class OrganizationalUnitAccessService
{
    /**
     * Ensure creators retain full control over newly created child units.
     */
    public function grantCreatorManageScopeOnNewChildUnit(User $user, OrganizationalUnit $unit): void
    {
        UserInternalOrganizationalScope::updateOrCreate(
            [
                'user_id' => $user->id,
                'organizational_unit_id' => $unit->id,
            ],
            [
                'access_level' => 'manage',
                'include_descendants' => false,
            ]
        );
    }

    /**
     * Move an organizational unit and preserve the actor's prior effective access.
     */
    public function reparentUnitForActor(User $user, OrganizationalUnit $unit, OrganizationalUnit $parent): void
    {
        $priorScope = $this->highestCurrentAccessScope($user, $unit);

        $unit->setParent($parent);

        $this->ensureActorCanAccessChildUnit($user, $unit, $priorScope);
    }

    /**
     * Ensure the acting user keeps their pre-move access to a child unit after hierarchy changes.
     */
    private function ensureActorCanAccessChildUnit(User $user, OrganizationalUnit $unit, ?UserInternalOrganizationalScope $priorScope): void
    {
        if ($priorScope === null) {
            return;
        }

        $currentScope = $this->highestCurrentAccessScope($user, $unit);

        if ($currentScope !== null && $this->scopeMatchesPinnedAccess($currentScope, $priorScope)) {
            return;
        }

        UserInternalOrganizationalScope::updateOrCreate(
            [
                'user_id' => $user->id,
                'organizational_unit_id' => $unit->id,
            ],
            [
                'access_level' => $priorScope->access_level,
                'include_descendants' => false,
                'min_viewable_rank' => $priorScope->min_viewable_rank,
                'max_viewable_rank' => $priorScope->max_viewable_rank,
                'min_assignable_rank' => $priorScope->min_assignable_rank,
                'max_assignable_rank' => $priorScope->max_assignable_rank,
                'allow_self_access' => $priorScope->allow_self_access,
            ]
        );
    }

    /**
     * Resolve the actor's highest currently applicable access level for the unit.
     */
    private function highestCurrentAccessScope(User $user, OrganizationalUnit $unit): ?UserInternalOrganizationalScope
    {
        /** @var UserInternalOrganizationalScope|null */
        return $user->getApplicableOrganizationalScopesForUnit($unit)
            ->sortByDesc(fn (UserInternalOrganizationalScope $scope): int => $scope->getAccessLevelValue())
            ->first();
    }

    private function scopeMatchesPinnedAccess(UserInternalOrganizationalScope $currentScope, UserInternalOrganizationalScope $priorScope): bool
    {
        return $currentScope->access_level === $priorScope->access_level
            && $currentScope->min_viewable_rank === $priorScope->min_viewable_rank
            && $currentScope->max_viewable_rank === $priorScope->max_viewable_rank
            && $currentScope->min_assignable_rank === $priorScope->min_assignable_rank
            && $currentScope->max_assignable_rank === $priorScope->max_assignable_rank
            && $currentScope->allow_self_access === $priorScope->allow_self_access;
    }
}
