<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\GuardBook;
use App\Models\ObjectArea;
use App\Models\SecPalObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating GuardBook model instances for testing.
 *
 * Note: Guard books must have EITHER object_id OR object_area_id set.
 * Use forObject() or forObjectArea() states to configure the relationship.
 *
 * @extends Factory<GuardBook>
 */
class GuardBookFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<GuardBook>
     */
    protected $model = GuardBook::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $prefix */
        $prefix = fake()->randomElement([
            'Wachbuch Haupteingang',
            'Wachbuch Empfang',
            'Wachbuch Außenbereich',
            'Wachbuch Tiefgarage',
            'Wachbuch Zentrale',
        ]);

        return [
            'tenant_id' => fake()->randomNumber(5),
            'object_id' => SecPalObject::factory(), // Default: object-wide
            'object_area_id' => null,
            'title' => $prefix.' '.fake()->company(),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'archived_at' => null,
        ];
    }

    /**
     * Configure the factory for a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Configure the factory for a specific object (object-wide guard book).
     */
    public function forObject(SecPalObject|string $object): static
    {
        $objectId = $object instanceof SecPalObject ? $object->id : $object;

        return $this->state(fn (array $attributes) => [
            'object_id' => $objectId,
            'object_area_id' => null, // Ensure area is null
        ]);
    }

    /**
     * Configure the factory for a specific object area (area-specific guard book).
     */
    public function forObjectArea(ObjectArea|string $area): static
    {
        $areaId = $area instanceof ObjectArea ? $area->id : $area;

        return $this->state(fn (array $attributes) => [
            'object_id' => null, // Ensure object is null
            'object_area_id' => $areaId,
        ]);
    }

    /**
     * Configure the factory for an archived (inactive) guard book.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'archived_at' => now(),
        ]);
    }

    /**
     * Configure the factory for an active guard book.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'archived_at' => null,
        ]);
    }

    /**
     * Configure the factory with a specific title.
     */
    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
