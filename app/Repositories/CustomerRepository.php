<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\TenantKey;

final class CustomerRepository
{
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

    public function lock(Customer $customer): Customer
    {
        return Customer::query()
            ->whereKey($customer->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function hasDomainDependencies(Customer $customer): bool
    {
        return $customer->customerEstablishments()->withTrashed()->exists()
            || $customer->sites()->exists();
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }
}
