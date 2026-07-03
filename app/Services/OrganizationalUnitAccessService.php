<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Support\Collection;

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
        $priorScopes = $this->currentAccessScopes($user, $unit);

        $unit->setParent($parent);

        $this->ensureActorCanAccessChildUnit($user, $unit, $priorScopes);
    }

    /**
     * Ensure the acting user keeps their pre-move access to a child unit after hierarchy changes.
     */
    private function ensureActorCanAccessChildUnit(User $user, OrganizationalUnit $unit, Collection $priorScopes): void
    {
        if ($priorScopes->isEmpty()) {
            return;
        }

        $currentScopes = $this->currentAccessScopes($user, $unit);

        if ($this->scopesMatchPinnedAccess($currentScopes, $priorScopes)) {
            return;
        }

        $priorScopes->each(function (UserInternalOrganizationalScope $priorScope) use ($user, $unit): void {
            UserInternalOrganizationalScope::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'organizational_unit_id' => $unit->id,
                    'access_level' => $priorScope->access_level,
                    'min_viewable_rank' => $priorScope->min_viewable_rank,
                    'max_viewable_rank' => $priorScope->max_viewable_rank,
                    'min_assignable_rank' => $priorScope->min_assignable_rank,
                    'max_assignable_rank' => $priorScope->max_assignable_rank,
                    'allow_self_access' => $priorScope->allow_self_access,
                ],
                [
                    'user_id' => $user->id,
                    'organizational_unit_id' => $unit->id,
                    'access_level' => $priorScope->access_level,
                    'include_descendants' => false,
                    'min_viewable_rank' => $priorScope->min_viewable_rank,
                    'max_viewable_rank' => $priorScope->max_viewable_rank,
                    'min_assignable_rank' => $priorScope->min_assignable_rank,
                    'max_assignable_rank' => $priorScope->max_assignable_rank,
                    'allow_self_access' => $priorScope->allow_self_access,
                ]
            );
        });
    }

    /**
     * Resolve the actor's currently applicable scopes for the unit, preserving all rank bands at the highest access level.
     *
     * @return Collection<int, UserInternalOrganizationalScope>
     */
    private function currentAccessScopes(User $user, OrganizationalUnit $unit): Collection
    {
        /** @var Collection<int, UserInternalOrganizationalScope> $scopes */
        $scopes = $user->getApplicableOrganizationalScopesForUnit($unit)->values();

        if ($scopes->isEmpty()) {
            return $scopes;
        }

        $highestAccessLevel = $scopes
            ->map(fn (UserInternalOrganizationalScope $scope): int => $scope->getAccessLevelValue())
            ->max();

        /** @var Collection<int, UserInternalOrganizationalScope> */
        return $scopes
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->getAccessLevelValue() === $highestAccessLevel)
            ->values();
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $priorScopes
     */
    private function scopesMatchPinnedAccess(Collection $currentScopes, Collection $priorScopes): bool
    {
        if ($currentScopes->count() !== $priorScopes->count()) {
            return false;
        }

        $normalizedCurrentScopes = $currentScopes
            ->map(fn (UserInternalOrganizationalScope $scope): string => $this->scopeFingerprint($scope))
            ->sort()
            ->values();

        $normalizedPriorScopes = $priorScopes
            ->map(fn (UserInternalOrganizationalScope $scope): string => $this->scopeFingerprint($scope))
            ->sort()
            ->values();

        return $normalizedCurrentScopes->all() === $normalizedPriorScopes->all();
    }

    private function scopeFingerprint(UserInternalOrganizationalScope $scope): string
    {
        return implode('|', [
            $scope->access_level,
            $scope->min_viewable_rank === null ? 'null' : (string) $scope->min_viewable_rank,
            $scope->max_viewable_rank === null ? 'null' : (string) $scope->max_viewable_rank,
            $scope->min_assignable_rank === null ? 'null' : (string) $scope->min_assignable_rank,
            $scope->max_assignable_rank === null ? 'null' : (string) $scope->max_assignable_rank,
            $scope->allow_self_access ? '1' : '0',
        ]);
    }
}
