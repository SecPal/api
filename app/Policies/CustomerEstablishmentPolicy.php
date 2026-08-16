<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerEstablishment;
use App\Models\User;
use App\Services\DomainAccessService;

final class CustomerEstablishmentPolicy
{
    public function __construct(private readonly DomainAccessService $domainAccess) {}

    public function viewAny(User $user): bool
    {
        return ($user->can('customers.read') && ! $user->organizationalScopes()->exists())
            || $user->hasAccessibleCustomers();
    }

    public function view(User $user, CustomerEstablishment $customerEstablishment): bool
    {
        return $this->domainAccess->customerEstablishmentIsVisible(
            $user,
            $customerEstablishment->tenant_id,
            $customerEstablishment,
        );
    }

    public function create(User $user): bool
    {
        return $user->can('customers.update') && ! $user->organizationalScopes()->exists();
    }

    public function update(User $user, CustomerEstablishment $customerEstablishment): bool
    {
        return $user->can('update', $customerEstablishment->customer);
    }

    public function delete(User $user, CustomerEstablishment $customerEstablishment): bool
    {
        return $user->can('update', $customerEstablishment->customer);
    }
}
