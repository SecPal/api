<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SecPalObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating SecPalObject model instances for testing.
 *
 * @extends Factory<SecPalObject>
 */
class SecPalObjectFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SecPalObject>
     */
    protected $model = SecPalObject::class;

    /**
     * The counter for generating unique object numbers.
     */
    private static int $objectNumberCounter = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $prefix */
        $prefix = fake()->randomElement([
            'Hauptgebäude',
            'Terminal A',
            'Lagerhalle Nord',
            'Rechenzentrum',
            'Bürokomplex',
            'Einkaufszentrum',
        ]);

        return [
            'tenant_id' => fake()->randomNumber(5),
            'customer_id' => Customer::factory(),
            'name' => $prefix.' '.fake()->streetAddress(),
            'object_number' => 'OBJ-'.str_pad((string) self::$objectNumberCounter++, 6, '0', STR_PAD_LEFT),
            'address' => fake()->address(),
            'gps_coordinates' => [
                'lat' => fake()->latitude(47.0, 55.0), // Germany latitude range
                'lon' => fake()->longitude(5.5, 15.0), // Germany longitude range
            ],
            'metadata' => null,
        ];
    }

    /**
     * Configure the factory for a specific customer.
     */
    public function forCustomer(Customer|string $customer): static
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        return $this->state(fn (array $attributes) => [
            'customer_id' => $customerId,
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
     * Configure the factory with specific GPS coordinates.
     */
    public function withCoordinates(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes) => [
            'gps_coordinates' => [
                'lat' => $latitude,
                'lon' => $longitude,
            ],
        ]);
    }

    /**
     * Configure the factory without GPS coordinates.
     */
    public function withoutCoordinates(): static
    {
        return $this->state(fn (array $attributes) => [
            'gps_coordinates' => null,
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
