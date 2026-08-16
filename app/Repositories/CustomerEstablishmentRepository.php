<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;

final class CustomerEstablishmentRepository
{
    public function lockIncludingTrashed(
        int $tenantId,
        string $customerId,
        string $establishmentId,
    ): ?CustomerEstablishment {
        return CustomerEstablishment::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('establishment_id', $establishmentId)
            ->lockForUpdate()
            ->first();
    }

    public function lockCustomer(int $tenantId, string $customerId): Customer
    {
        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($customerId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockEstablishment(int $tenantId, string $establishmentId): Establishment
    {
        return Establishment::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($establishmentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): CustomerEstablishment
    {
        return CustomerEstablishment::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        CustomerEstablishment $customerEstablishment,
        array $attributes,
    ): CustomerEstablishment {
        $customerEstablishment->update($attributes);

        return $customerEstablishment->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function restore(CustomerEstablishment $customerEstablishment, array $attributes): CustomerEstablishment
    {
        $customerEstablishment->restore();
        $customerEstablishment->update($attributes);

        return $customerEstablishment->refresh();
    }

    public function hasSites(CustomerEstablishment $customerEstablishment): bool
    {
        return \App\Models\Site::query()
            ->where('tenant_id', $customerEstablishment->tenant_id)
            ->where('customer_id', $customerEstablishment->customer_id)
            ->where('establishment_id', $customerEstablishment->establishment_id)
            ->exists();
    }

    public function delete(CustomerEstablishment $customerEstablishment): void
    {
        $customerEstablishment->delete();
    }
}
