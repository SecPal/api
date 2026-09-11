<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkInstructionStandardBlock;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkInstructionStandardBlockPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasReadCapability($user);
    }

    public function view(User $user, WorkInstructionStandardBlock $standardBlock): bool
    {
        return $this->hasReadCapability($user);
    }

    private function hasReadCapability(User $user): bool
    {
        $tenantId = $this->permissions->getPermissionsTeamId();

        return is_int($tenantId)
            && $user->tenant_id !== null
            && $user->tenant_id === $tenantId
            && $user->can('work_instructions.read');
    }
}
