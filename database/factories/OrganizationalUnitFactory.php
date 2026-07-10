<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Factories;

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
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
        // Get the most recently created tenant from the current test context.
        // Don't cache between tests (RefreshDatabase clears everything)
        $tenant = TenantKey::query()->latest('id')->first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        return [
            'tenant_id' => $tenant->id,
            'type' => fake()->randomElement([
                'holding', 'company', 'region', 'branch', 'department', 'division', 'custom',
            ]),
            'name' => fake()->company(),
            'custom_type_name' => null,
            'description' => fake()->optional()->sentence(),
            'metadata' => null,
            'is_legal_entity' => false,
            'is_establishment' => false,
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
