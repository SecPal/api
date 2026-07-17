<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerEstablishment;
use App\Models\User;

final class CustomerEstablishmentPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->can('customers.read') && ! $user->organizationalScopes()->exists())
            || $user->hasAccessibleCustomers();
    }

    public function view(User $user, CustomerEstablishment $customerEstablishment): bool
    {
        return $user->can('view', $customerEstablishment->customer);
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
