<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\InternalCostCenter;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

final readonly class InternalCostCenterPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'internal_cost_centers.read');
    }

    public function view(User $user, InternalCostCenter $internalCostCenter): bool
    {
        return $this->hasModelCapability($user, $internalCostCenter, 'internal_cost_centers.read');
    }

    public function create(User $user): bool
    {
        return $this->hasCapability($user, 'internal_cost_centers.create');
    }

    public function update(User $user, ?InternalCostCenter $internalCostCenter = null): bool
    {
        return $this->hasModelCapability($user, $internalCostCenter, 'internal_cost_centers.update');
    }

    public function deactivate(User $user, ?InternalCostCenter $internalCostCenter = null): bool
    {
        return $this->hasModelCapability($user, $internalCostCenter, 'internal_cost_centers.deactivate');
    }

    private function hasModelCapability(
        User $user,
        ?InternalCostCenter $internalCostCenter,
        string $permission,
    ): bool {
        return $this->hasCapability($user, $permission)
            && ($internalCostCenter === null || $user->tenant_id === $internalCostCenter->tenant_id);
    }

    private function hasCapability(User $user, string $permission): bool
    {
        $tenantId = $this->permissions->getPermissionsTeamId();

        return is_int($tenantId)
            && $user->tenant_id !== null
            && $user->tenant_id === $tenantId
            && $user->can($permission);
    }
}
