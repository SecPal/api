<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InternalCostCenterStatus;
use App\Models\InternalCostCenter;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InternalCostCenter> */
class InternalCostCenterFactory extends Factory
{
    protected $model = InternalCostCenter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'code' => 'ICC-'.Str::upper(Str::random(12)),
            'name' => fake()->words(3, true),
            'status' => InternalCostCenterStatus::Active,
            'inactive_at' => null,
        ];
    }

    public function forTenant(int|string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => InternalCostCenterStatus::Inactive,
            'inactive_at' => now(),
        ]);
    }
}
