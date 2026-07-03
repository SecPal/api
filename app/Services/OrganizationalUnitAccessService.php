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
        $subtreeUnits = $this->subtreeUnits($unit);
        $priorScopesByUnit = $this->snapshotCurrentAccessScopes($user, $subtreeUnits);

        $unit->setParent($parent);

        $subtreeUnits->each(function (OrganizationalUnit $subtreeUnit) use ($user, $priorScopesByUnit): void {
            /** @var Collection<int, UserInternalOrganizationalScope> $priorScopes */
            $priorScopes = $priorScopesByUnit->get($subtreeUnit->id, collect());

            $this->ensureActorCanAccessChildUnit($user, $subtreeUnit, $priorScopes);
        });
    }

    /**
     * Ensure the acting user keeps their pre-move access to a child unit after hierarchy changes.
     *
     * @param  Collection<int, UserInternalOrganizationalScope>  $priorScopes
     */
    private function ensureActorCanAccessChildUnit(User $user, OrganizationalUnit $unit, Collection $priorScopes): void
    {
        if ($priorScopes->isEmpty()) {
            return;
        }

        $currentScopes = $this->applicableAccessScopes($user, $unit);

        if ($this->scopesMatchPinnedAccess($currentScopes, $priorScopes)) {
            return;
        }

        $priorScopes->each(function (UserInternalOrganizationalScope $priorScope) use ($user, $unit): void {
            UserInternalOrganizationalScope::firstOrCreate(
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
     * @return Collection<int, OrganizationalUnit>
     */
    private function subtreeUnits(OrganizationalUnit $unit): Collection
    {
        /** @var Collection<int, OrganizationalUnit> $subtreeUnits */
        $subtreeUnits = $unit->descendants()->get()->prepend($unit)->values();

        return $subtreeUnits;
    }

    /**
     * @param  Collection<int, OrganizationalUnit>  $subtreeUnits
     * @return Collection<string, Collection<int, UserInternalOrganizationalScope>>
     */
    private function snapshotCurrentAccessScopes(User $user, Collection $subtreeUnits): Collection
    {
        return $subtreeUnits->mapWithKeys(function (OrganizationalUnit $subtreeUnit) use ($user): array {
            return [$subtreeUnit->id => $this->applicableAccessScopes($user, $subtreeUnit)];
        });
    }

    /**
     * Resolve all currently applicable scopes for the unit.
     *
     * @return Collection<int, UserInternalOrganizationalScope>
     */
    private function applicableAccessScopes(User $user, OrganizationalUnit $unit): Collection
    {
        /** @var Collection<int, UserInternalOrganizationalScope> $scopes */
        $scopes = $user->getApplicableOrganizationalScopesForUnit($unit)->values();

        return $scopes;
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
