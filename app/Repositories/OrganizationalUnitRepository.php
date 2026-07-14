<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Collection;

final class OrganizationalUnitRepository
{
    /**
     * @return Collection<int, OrganizationalUnit>
     */
    public function activeLegalEntitiesForTenant(int $tenantId): Collection
    {
        return OrganizationalUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('is_legal_entity', true)
            ->where('is_active', true)
            ->where('is_assignable', true)
            ->orderBy('name')
            ->get();
    }

    public function lockActiveLegalEntity(int $tenantId, string $legalEntityId): ?OrganizationalUnit
    {
        return OrganizationalUnit::query()
            ->whereKey($legalEntityId)
            ->where('tenant_id', $tenantId)
            ->where('is_legal_entity', true)
            ->where('is_active', true)
            ->where('is_assignable', true)
            ->lockForUpdate()
            ->first();
    }

    public function lockUnit(OrganizationalUnit $organizationalUnit): OrganizationalUnit
    {
        return OrganizationalUnit::query()
            ->whereKey($organizationalUnit->id)
            ->where('tenant_id', $organizationalUnit->tenant_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function hasLinkedCustomers(OrganizationalUnit $organizationalUnit): bool
    {
        return Customer::withTrashed()
            ->where('tenant_id', $organizationalUnit->tenant_id)
            ->where('legal_entity_id', $organizationalUnit->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(OrganizationalUnit $organizationalUnit, array $attributes): OrganizationalUnit
    {
        $organizationalUnit->update($attributes);

        return $organizationalUnit;
    }

    public function delete(OrganizationalUnit $organizationalUnit): void
    {
        $organizationalUnit->delete();
    }
}
