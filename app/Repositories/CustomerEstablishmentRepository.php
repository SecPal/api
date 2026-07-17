<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;

final class CustomerEstablishmentRepository
{
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
}
