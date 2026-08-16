<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitClosure;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Support\Collection;

class OrganizationalScopeEntitlementService
{
    /**
     * @param  array{access_level?: string, include_descendants?: bool, min_viewable_rank?: int|null, max_viewable_rank?: int|null, min_assignable_rank?: int|null, max_assignable_rank?: int|null, allow_self_access?: bool}  $validated
     */
    public function updateExpandsEntitlement(UserInternalOrganizationalScope $scope, array $validated): bool
    {
        $updatedScope = clone $scope;
        $updatedScope->fill($validated);

        if (
            ! $scope->include_descendants
            && $updatedScope->include_descendants
            && $updatedScope->hasMinimumAccessLevel('read')
        ) {
            return true;
        }

        return $this->scopeSetExpandsEntitlement(collect([$scope]), collect([$updatedScope]));
    }

    public function targetsAcceptNewEntitlements(
        OrganizationalUnit $organizationalUnit,
        bool $includeDescendants,
        string $userId,
    ): bool {
        $organizationalUnitIds = collect([$organizationalUnit->id]);
        $descendantIds = collect();

        if ($includeDescendants) {
            $descendantIds = OrganizationalUnitClosure::query()
                ->where('ancestor_id', $organizationalUnit->id)
                ->where('depth', '>', 0)
                ->pluck('descendant_id');
            $organizationalUnitIds = $organizationalUnitIds->merge($descendantIds);
        }

        $maskedDescendantIds = UserInternalOrganizationalScope::query()
            ->where('user_id', $userId)
            ->whereIn('organizational_unit_id', $descendantIds)
            ->get(['organizational_unit_id', 'access_level', 'include_descendants'])
            ->pipe(function (Collection $scopes): Collection {
                $maskedIds = $scopes->pluck('organizational_unit_id');
                $denyAncestorIds = $scopes
                    ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->access_level === 'none' && $scope->include_descendants)
                    ->pluck('organizational_unit_id');

                if ($denyAncestorIds->isNotEmpty()) {
                    $maskedIds = $maskedIds->merge(
                        OrganizationalUnitClosure::query()
                            ->whereIn('ancestor_id', $denyAncestorIds)
                            ->where('depth', '>', 0)
                            ->pluck('descendant_id'),
                    );
                }

                return $maskedIds;
            });

        return ! OrganizationalUnit::withTrashed()
            ->where('tenant_id', $organizationalUnit->tenant_id)
            ->whereIn('id', $organizationalUnitIds->unique())
            ->whereNotIn('id', $maskedDescendantIds)
            ->where(fn ($query) => $query
                ->whereNotNull('deleted_at')
                ->orWhere('is_assignable', false))
            ->exists();
    }

    public function removingScopeExpandsClosedUnitEntitlement(
        OrganizationalUnit $organizationalUnit,
        UserInternalOrganizationalScope $scope,
        bool $includeScopeUnit = true,
    ): bool {
        $user = User::query()->find($scope->user_id);

        if (! $user instanceof User) {
            return false;
        }

        $organizationalUnitIds = $includeScopeUnit ? collect([$organizationalUnit->id]) : collect();

        if ($scope->include_descendants) {
            $organizationalUnitIds = $organizationalUnitIds->merge(
                OrganizationalUnitClosure::query()
                    ->where('ancestor_id', $organizationalUnit->id)
                    ->where('depth', '>', 0)
                    ->pluck('descendant_id'),
            );
        }

        $currentScopeSet = $user->organizationalScopes()->get();
        $replacementScopeSet = $currentScopeSet->where('id', '!=', $scope->id)->values();

        return OrganizationalUnit::withTrashed()
            ->where('tenant_id', $organizationalUnit->tenant_id)
            ->whereIn('id', $organizationalUnitIds->unique())
            ->where(fn ($query) => $query
                ->whereNotNull('deleted_at')
                ->orWhere('is_assignable', false))
            ->get()
            ->contains(function (OrganizationalUnit $coveredUnit) use ($user, $currentScopeSet, $replacementScopeSet): bool {
                $currentScopes = $user->getApplicableOrganizationalScopesForUnitUsingScopes($coveredUnit, $currentScopeSet);
                $replacementScopes = $user->getApplicableOrganizationalScopesForUnitUsingScopes($coveredUnit, $replacementScopeSet);

                return $this->scopeSetExpandsEntitlement($currentScopes, $replacementScopes);
            });
    }

    /**
     * @param  Collection<int, UserInternalOrganizationalScope>  $currentScopes
     * @param  Collection<int, UserInternalOrganizationalScope>  $replacementScopes
     */
    private function scopeSetExpandsEntitlement(Collection $currentScopes, Collection $replacementScopes): bool
    {
        $currentAccessLevel = $currentScopes->max(
            fn (UserInternalOrganizationalScope $scope): int => $scope->getAccessLevelValue(),
        ) ?? 0;
        $replacementAccessLevel = $replacementScopes->max(
            fn (UserInternalOrganizationalScope $scope): int => $scope->getAccessLevelValue(),
        ) ?? 0;

        if ($replacementAccessLevel > $currentAccessLevel) {
            return true;
        }

        for ($managementLevel = 0; $managementLevel <= 255; $managementLevel++) {
            if (
                (! $this->scopeSetCanViewManagementLevel($currentScopes, $managementLevel)
                    && $this->scopeSetCanViewManagementLevel($replacementScopes, $managementLevel))
                || (! $this->scopeSetCanAssignManagementLevel($currentScopes, $managementLevel)
                    && $this->scopeSetCanAssignManagementLevel($replacementScopes, $managementLevel))
            ) {
                return true;
            }
        }

        return ! $this->scopeSetAllowsSelfAccess($currentScopes)
            && $this->scopeSetAllowsSelfAccess($replacementScopes);
    }

    /** @param Collection<int, UserInternalOrganizationalScope> $scopes */
    private function scopeSetCanViewManagementLevel(Collection $scopes, int $managementLevel): bool
    {
        return $scopes->contains(
            fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel('read')
                && $scope->canViewManagementLevel($managementLevel),
        );
    }

    /** @param Collection<int, UserInternalOrganizationalScope> $scopes */
    private function scopeSetCanAssignManagementLevel(Collection $scopes, int $managementLevel): bool
    {
        return $scopes->contains(
            fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel('write')
                && $scope->canAssignManagementLevel($managementLevel),
        );
    }

    /** @param Collection<int, UserInternalOrganizationalScope> $scopes */
    private function scopeSetAllowsSelfAccess(Collection $scopes): bool
    {
        return $scopes->contains(
            fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel('read')
                && $scope->allow_self_access,
        );
    }
}
