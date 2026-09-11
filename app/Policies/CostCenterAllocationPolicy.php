<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

final readonly class CostCenterAllocationPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'cost_center_allocations.read');
    }

    public function update(User $user): bool
    {
        return $this->hasCapability($user, 'cost_center_allocations.update');
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
