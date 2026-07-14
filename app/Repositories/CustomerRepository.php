<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CustomerRepository
{
    /**
     * Build the customer query permitted by the user's tenant permissions and scopes.
     *
     * @return Builder<Customer>
     */
    public function visibleQuery(User $user, int $tenantId): Builder
    {
        if ($user->can('customers.read')) {
            return Customer::query()
                ->where('tenant_id', $tenantId)
                ->with(['assignments.user']);
        }

        return $user->accessibleCustomersQuery()
            ->with(['assignments.user']);
    }

    public function nextCustomerNumber(int $tenantId): string
    {
        return Customer::generateCustomerNumber($tenantId);
    }

    public function lockTenant(int $tenantId): void
    {
        TenantKey::query()
            ->select('id')
            ->whereKey($tenantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Customer
    {
        return Customer::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);
        $customer->refresh();

        return $customer;
    }
}
