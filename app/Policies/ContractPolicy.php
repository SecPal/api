<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

final readonly class ContractPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'contracts.read');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $this->hasModelCapability($user, $contract, 'contracts.read');
    }

    public function create(User $user): bool
    {
        return $this->hasCapability($user, 'contracts.create');
    }

    public function update(User $user, ?Contract $contract = null): bool
    {
        return $this->hasModelCapability($user, $contract, 'contracts.update');
    }

    public function retire(User $user, ?Contract $contract = null): bool
    {
        return $this->hasModelCapability($user, $contract, 'contracts.retire');
    }

    private function hasModelCapability(User $user, ?Contract $contract, string $permission): bool
    {
        return $this->hasCapability($user, $permission)
            && ($contract === null || $user->tenant_id === $contract->tenant_id);
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
