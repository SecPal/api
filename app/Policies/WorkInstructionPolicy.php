<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkInstruction;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkInstructionPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'work_instructions.read');
    }

    public function view(User $user, WorkInstruction $workInstruction): bool
    {
        return $this->hasModelCapability($user, $workInstruction, 'work_instructions.read');
    }

    public function create(User $user): bool
    {
        return $this->hasCapability($user, 'work_instructions.create');
    }

    public function update(User $user, ?WorkInstruction $workInstruction = null): bool
    {
        return $this->hasModelCapability($user, $workInstruction, 'work_instructions.update');
    }

    public function publish(User $user, ?WorkInstruction $workInstruction = null): bool
    {
        return $this->hasModelCapability($user, $workInstruction, 'work_instructions.publish');
    }

    public function archive(User $user, ?WorkInstruction $workInstruction = null): bool
    {
        return $this->hasModelCapability($user, $workInstruction, 'work_instructions.archive');
    }

    private function hasModelCapability(
        User $user,
        ?WorkInstruction $workInstruction,
        string $permission,
    ): bool {
        return $this->hasCapability($user, $permission)
            && ($workInstruction === null || $user->tenant_id === $workInstruction->tenant_id);
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
