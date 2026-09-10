<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceBooking;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

final readonly class ServiceBookingPolicy
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->hasCapability($user, 'service_bookings.read');
    }

    public function view(User $user, ServiceBooking $serviceBooking): bool
    {
        return $this->hasModelCapability($user, $serviceBooking, 'service_bookings.read');
    }

    public function create(User $user): bool
    {
        return $this->hasCapability($user, 'service_bookings.create');
    }

    public function update(User $user, ?ServiceBooking $serviceBooking = null): bool
    {
        return $this->hasModelCapability($user, $serviceBooking, 'service_bookings.update');
    }

    public function retire(User $user, ?ServiceBooking $serviceBooking = null): bool
    {
        return $this->hasModelCapability($user, $serviceBooking, 'service_bookings.retire');
    }

    private function hasModelCapability(
        User $user,
        ?ServiceBooking $serviceBooking,
        string $permission,
    ): bool {
        return $this->hasCapability($user, $permission)
            && ($serviceBooking === null || $user->tenant_id === $serviceBooking->tenant_id);
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
