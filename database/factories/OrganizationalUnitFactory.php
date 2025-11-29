<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationalUnit>
 */
class OrganizationalUnitFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<OrganizationalUnit>
     */
    protected $model = OrganizationalUnit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fake()->uuid(),
            'type' => fake()->randomElement([
                'holding', 'company', 'region', 'branch', 'department', 'division', 'custom',
            ]),
            'name' => fake()->company(),
            'custom_type_name' => null,
            'description' => fake()->optional()->sentence(),
            'metadata' => null,
        ];
    }

    /**
     * Configure the factory for a holding company type.
     */
    public function holding(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'holding',
        ]);
    }

    /**
     * Configure the factory for a company type.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'company',
        ]);
    }

    /**
     * Configure the factory for a department type.
     */
    public function department(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'department',
        ]);
    }

    /**
     * Configure the factory for a custom type with a custom name.
     */
    public function customType(string $customTypeName): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'custom',
            'custom_type_name' => $customTypeName,
        ]);
    }

    /**
     * Configure the factory with a specific tenant.
     */
    public function forTenant(string $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
