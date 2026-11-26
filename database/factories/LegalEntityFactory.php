<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LegalEntity;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegalEntity> */
class LegalEntityFactory extends Factory
{
    protected $model = LegalEntity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $tenant = Tenant::query()->latest('id')->first() ?? Tenant::factory()->create();

        return [
            'tenant_id' => $tenant->id,
            'name' => fake()->company(),
            'is_active' => true,
        ];
    }

    public function forTenant(int|string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId]);
    }
}
