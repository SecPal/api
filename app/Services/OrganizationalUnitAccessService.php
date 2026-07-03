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
        $priorAccessLevel = $this->highestCurrentAccessLevel($user, $unit);

        $unit->setParent($parent);

        $this->ensureActorCanAccessChildUnit($user, $unit, $priorAccessLevel);
    }

    /**
     * Ensure the acting user keeps their pre-move access to a child unit after hierarchy changes.
     */
    private function ensureActorCanAccessChildUnit(User $user, OrganizationalUnit $unit, string $priorAccessLevel): void
    {
        if ($user->hasAccessToUnit($unit, 'read')) {
            return;
        }

        UserInternalOrganizationalScope::firstOrCreate(
            [
                'user_id' => $user->id,
                'organizational_unit_id' => $unit->id,
            ],
            [
                'access_level' => $priorAccessLevel,
                'include_descendants' => false,
            ]
        );
    }

    /**
     * Resolve the actor's highest currently applicable access level for the unit.
     */
    private function highestCurrentAccessLevel(User $user, OrganizationalUnit $unit): string
    {
        /** @var UserInternalOrganizationalScope|null $scope */
        $scope = $user->getApplicableOrganizationalScopesForUnit($unit)
            ->sortByDesc(fn (UserInternalOrganizationalScope $scope): int => $scope->getAccessLevelValue())
            ->first();

        if ($scope === null) {
            return 'read';
        }

        return $scope->access_level;
    }
}
