<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Establishment> */
class EstablishmentFactory extends Factory
{
    protected $model = Establishment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $tenant = TenantKey::query()->latest('id')->first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        }

        return [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => LegalEntity::factory()->forTenant($tenant->id),
            'name' => fake()->city().' Establishment',
            'is_active' => true,
        ];
    }

    public function forTenant(int|string $tenantId): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenantId,
            'legal_entity_id' => LegalEntity::factory()->forTenant($tenantId),
        ]);
    }
}
