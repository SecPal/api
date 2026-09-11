<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkInstructionTemplate;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkInstructionTemplatePolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'work_instructions.read');
    }

    public function view(User $user, WorkInstructionTemplate $template): bool
    {
        return $this->hasCapability($user, 'work_instructions.read')
            && $user->tenant_id === $template->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->hasCapability($user, 'work_instructions.create');
    }

    public function update(User $user, ?WorkInstructionTemplate $template = null): bool
    {
        return $this->hasCapability($user, 'work_instructions.update')
            && ($template === null || $user->tenant_id === $template->tenant_id);
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
