<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Support\Collection;

class SelfScopeAuthorizationExpansionService
{
    public function doesNotExpandAuthorizationComparedTo(
        UserInternalOrganizationalScope $candidateScope,
        UserInternalOrganizationalScope $baselineScope,
    ): bool {
        return $candidateScope->getAccessLevelValue() <= $baselineScope->getAccessLevelValue()
            && (! $candidateScope->include_descendants || $baselineScope->include_descendants)
            && (! $candidateScope->allow_self_access || $baselineScope->allow_self_access)
            && $this->managementLevelRangeIsSubsetOf($candidateScope, $baselineScope, assignable: false)
            && $this->managementLevelRangeIsSubsetOf($candidateScope, $baselineScope, assignable: true);
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $simulatedScopes
     */
    public function effectiveAuthorizationExpands(
        User $actor,
        UserInternalOrganizationalScope $currentScope,
        UserInternalOrganizationalScope $simulatedScope,
        Collection $currentScopes,
        Collection $simulatedScopes,
    ): bool {
        foreach ($this->affectedUnitsForSelfScopeChange($currentScope, $simulatedScope) as $organizationalUnit) {
            foreach (['read', 'write', 'manage'] as $minimumAccessLevel) {
                if (
                    $actor->hasAccessToUnit($organizationalUnit, $minimumAccessLevel, $simulatedScopes)
                    && ! $actor->hasAccessToUnit($organizationalUnit, $minimumAccessLevel, $currentScopes)
                ) {
                    return true;
                }
            }

            $currentViewScopes = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $currentScopes, 'read');
            $simulatedViewScopes = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $simulatedScopes, 'read');

            if ($this->selfAccessAuthorizationExpands($actor, $organizationalUnit, $currentScopes, $simulatedScopes)) {
                return true;
            }

            if ($this->managementLevelAuthorizationExpands(
                $currentViewScopes,
                $simulatedViewScopes,
                fn (UserInternalOrganizationalScope $scope, int $managementLevel): bool => $scope->canViewManagementLevel($managementLevel),
            )) {
                return true;
            }

            $currentWriteScopes = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $currentScopes, 'write');
            $simulatedWriteScopes = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $simulatedScopes, 'write');

            if ($this->managementLevelAuthorizationExpands(
                $currentWriteScopes,
                $simulatedWriteScopes,
                fn (UserInternalOrganizationalScope $scope, int $managementLevel): bool => $scope->canAssignManagementLevel($managementLevel),
            )) {
                return true;
            }

            if ($this->managementLevelAuthorizationExpands(
                $currentWriteScopes,
                $simulatedWriteScopes,
                fn (UserInternalOrganizationalScope $scope, int $managementLevel): bool => $scope->canViewManagementLevel($managementLevel)
                    && $scope->canAssignManagementLevel($managementLevel),
            )) {
                return true;
            }
        }

        return false;
    }

    private function managementLevelRangeIsSubsetOf(
        UserInternalOrganizationalScope $candidateScope,
        UserInternalOrganizationalScope $baselineScope,
        bool $assignable,
    ): bool {
        foreach (range(0, 255) as $managementLevel) {
            $candidateScopeAllowsLevel = $assignable
                ? $candidateScope->canAssignManagementLevel($managementLevel)
                : $candidateScope->canViewManagementLevel($managementLevel);

            if (! $candidateScopeAllowsLevel) {
                continue;
            }

            $baselineScopeAllowsLevel = $assignable
                ? $baselineScope->canAssignManagementLevel($managementLevel)
                : $baselineScope->canViewManagementLevel($managementLevel);

            if (! $baselineScopeAllowsLevel) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $simulatedScopes
     */
    private function selfAccessAuthorizationExpands(
        User $actor,
        OrganizationalUnit $organizationalUnit,
        Collection $currentScopes,
        Collection $simulatedScopes,
    ): bool {
        foreach (['read', 'write'] as $minimumAccessLevel) {
            $currentScopedSelfAccess = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $currentScopes, $minimumAccessLevel)
                ->contains(fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access);

            $simulatedScopedSelfAccess = $this->applicableScopesWithMinimumAccessLevel($actor, $organizationalUnit, $simulatedScopes, $minimumAccessLevel)
                ->contains(fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access);

            if ($simulatedScopedSelfAccess && ! $currentScopedSelfAccess) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, OrganizationalUnit>
     */
    private function affectedUnitsForSelfScopeChange(
        UserInternalOrganizationalScope $currentScope,
        UserInternalOrganizationalScope $simulatedScope,
    ): Collection {
        $organizationalUnit = $currentScope->organizationalUnit;

        if ($organizationalUnit === null) {
            /** @var Collection<int, OrganizationalUnit> $emptyUnits */
            $emptyUnits = collect();

            return $emptyUnits;
        }

        /** @var Collection<int, OrganizationalUnit> $affectedUnits */
        $affectedUnits = collect([$organizationalUnit]);

        if ($currentScope->include_descendants || $simulatedScope->include_descendants) {
            $descendantIds = \App\Models\OrganizationalUnitClosure::query()
                ->where('ancestor_id', $organizationalUnit->id)
                ->where('depth', '>', 0)
                ->pluck('descendant_id');

            /** @var Collection<int, OrganizationalUnit> $descendants */
            $descendants = OrganizationalUnit::withTrashed()
                ->whereIn('id', $descendantIds)
                ->get();

            $affectedUnits = $affectedUnits
                ->merge($descendants)
                ->unique('id')
                ->values();
        }

        return $affectedUnits;
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $scopes
     * @return Collection<int, UserInternalOrganizationalScope>
     */
    private function applicableScopesWithMinimumAccessLevel(
        User $actor,
        OrganizationalUnit $organizationalUnit,
        Collection $scopes,
        string $minimumAccessLevel,
    ): Collection {
        return $actor->getApplicableOrganizationalScopesForUnitUsingScopes($organizationalUnit, $scopes)
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel($minimumAccessLevel))
            ->values();
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $simulatedScopes
     * @param  callable(UserInternalOrganizationalScope, int): bool  $authorizesManagementLevel
     */
    private function managementLevelAuthorizationExpands(
        Collection $currentScopes,
        Collection $simulatedScopes,
        callable $authorizesManagementLevel,
    ): bool {
        foreach (range(0, 255) as $managementLevel) {
            $isNewlyAuthorized = $simulatedScopes->contains(
                fn (UserInternalOrganizationalScope $scope): bool => $authorizesManagementLevel($scope, $managementLevel)
            );

            if (! $isNewlyAuthorized) {
                continue;
            }

            $wasPreviouslyAuthorized = $currentScopes->contains(
                fn (UserInternalOrganizationalScope $scope): bool => $authorizesManagementLevel($scope, $managementLevel)
            );

            if (! $wasPreviouslyAuthorized) {
                return true;
            }
        }

        return false;
    }
}
