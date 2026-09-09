<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\LegalHold;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

final readonly class LegalHoldPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'legal_holds.read');
    }

    public function view(User $user, LegalHold $legalHold): bool
    {
        return $this->hasCapability($user, 'legal_holds.read')
            && $user->tenant_id === $legalHold->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->hasCapability($user, 'legal_holds.create');
    }

    public function attach(User $user, ?LegalHold $legalHold = null): bool
    {
        return $this->hasModelCapability($user, $legalHold, 'legal_holds.attach');
    }

    public function detach(User $user, ?LegalHold $legalHold = null): bool
    {
        return $this->hasModelCapability($user, $legalHold, 'legal_holds.detach');
    }

    public function release(User $user, ?LegalHold $legalHold = null): bool
    {
        return $this->hasModelCapability($user, $legalHold, 'legal_holds.release');
    }

    private function hasModelCapability(User $user, ?LegalHold $legalHold, string $permission): bool
    {
        return $this->hasCapability($user, $permission)
            && ($legalHold === null || $user->tenant_id === $legalHold->tenant_id);
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
