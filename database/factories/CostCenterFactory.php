<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CostCenter;
use App\Models\Site;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for generating CostCenter model instances with test data.
 *
 * @extends Factory<CostCenter>
 */
class CostCenterFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CostCenter>
     */
    protected $model = CostCenter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = TenantKey::query()->latest('id')->first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        $sequence = $this->faker->unique()->numberBetween(1, 999);

        return [
            'tenant_id' => $tenant->id,
            'site_id' => Site::factory(),
            'code' => sprintf('KST-%03d', $sequence),
            'name' => $this->faker->randomElement([
                'Reception Duty',
                'Night Shift Security',
                'Event Security',
                'Building Inspection',
                'Patrol Service',
                'Access Control',
                'Fire Watch',
                'Empfangsdienst',
                'Nachtschicht',
                'Objektschutz',
                'Streifendienst',
            ]),
            'activity_type' => $this->faker->optional(0.7)->randomElement([
                'Security Guard',
                'Reception',
                'Patrol',
                'Fire Safety',
                'Access Control',
                'Event Security',
            ]),
            'description' => $this->faker->optional(0.5)->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the cost center is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the cost center has no activity type.
     */
    public function withoutActivityType(): static
    {
        return $this->state(fn (array $attributes): array => [
            'activity_type' => null,
        ]);
    }
}
