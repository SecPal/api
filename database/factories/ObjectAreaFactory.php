<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\ObjectArea;
use App\Models\SecPalObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating ObjectArea model instances for testing.
 *
 * @extends Factory<ObjectArea>
 */
class ObjectAreaFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ObjectArea>
     */
    protected $model = ObjectArea::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fake()->randomNumber(5),
            'object_id' => SecPalObject::factory(),
            'name' => fake()->randomElement([
                'Eingangsbereich',
                'Parkdeck A',
                'Serverraum',
                'Lagerzone Nord',
                'Terminal Gate B',
                'Empfangshalle',
                'Produktionshalle',
                'Verwaltungsgebäude',
            ]),
            'description' => fake()->optional(0.5)->sentence(),
            'requires_separate_guard_book' => fake()->boolean(20), // 20% chance of separate book
            'gps_boundaries' => null, // Complex GeoJSON - typically set explicitly
            'metadata' => null,
        ];
    }

    /**
     * Configure the factory for a specific object.
     */
    public function forObject(SecPalObject|string $object): static
    {
        $objectId = $object instanceof SecPalObject ? $object->id : $object;

        return $this->state(fn (array $attributes) => [
            'object_id' => $objectId,
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
     * Configure the factory with a separate guard book.
     */
    public function withSeparateGuardBook(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_separate_guard_book' => true,
        ]);
    }

    /**
     * Configure the factory without a separate guard book.
     */
    public function withoutSeparateGuardBook(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_separate_guard_book' => false,
        ]);
    }

    /**
     * Configure the factory with a GPS boundary polygon.
     *
     * @param  array<int, array{lat: float, lon: float}>  $coordinates  Array of {lat, lon} pairs
     */
    public function withBoundary(array $coordinates): static
    {
        return $this->state(fn (array $attributes) => [
            'gps_boundaries' => $coordinates,
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
