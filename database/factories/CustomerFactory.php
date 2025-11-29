<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating Customer model instances for testing.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Customer>
     */
    protected $model = Customer::class;

    /**
     * The counter for generating unique customer numbers.
     */
    private static int $customerNumberCounter = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fake()->randomNumber(5),
            'managed_by_organizational_unit_id' => null,
            'name' => fake()->company(),
            'customer_number' => 'CUST-'.str_pad((string) self::$customerNumberCounter++, 6, '0', STR_PAD_LEFT),
            'type' => fake()->randomElement(['corporate', 'regional', 'local']),
            'address' => fake()->address(),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'metadata' => null,
        ];
    }

    /**
     * Configure the factory for a corporate customer.
     */
    public function corporate(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'corporate',
        ]);
    }

    /**
     * Configure the factory for a regional customer.
     */
    public function regional(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'regional',
        ]);
    }

    /**
     * Configure the factory for a local customer.
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'local',
        ]);
    }

    /**
     * Configure the factory for a custom type.
     */
    public function customType(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'custom',
        ]);
    }

    /**
     * Configure the factory with a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Configure the factory with a managing organizational unit.
     */
    public function managedBy(string $organizationalUnitId): static
    {
        return $this->state(fn (array $attributes) => [
            'managed_by_organizational_unit_id' => $organizationalUnitId,
        ]);
    }

    /**
     * Configure the factory with specific metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata,
        ]);
    }
}
