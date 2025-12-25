<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeadershipLevel;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for LeadershipLevel model.
 *
 * Creates tenant-specific leadership level definitions for hierarchical
 * access control per ADR-009 (Leadership-Based Access Control).
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/423 Issue #423: Leadership Levels Database Migrations
 * @see docs/GUARD_ARCHITECTURE.md ADR-009: Permission Inheritance Blocking & Leadership-Based Access Control
 *
 * @extends Factory<LeadershipLevel>
 */
final class LeadershipLevelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<LeadershipLevel>
     */
    protected $model = LeadershipLevel::class;

    /**
     * Define the model's default state.
     *
     * Generates a leadership level with a random rank (1-10) and unique name.
     * The rank follows ADR-009 convention: 1 = highest authority (CEO),
     * ascending numbers = lower levels.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'rank' => fake()->unique()->numberBetween(1, 255),
            'name' => sprintf('%s (%s)', fake()->jobTitle(), Str::random(8)),
            'description' => fake()->optional(0.7)->sentence(),
            'color' => fake()->optional(0.5)->hexColor(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the leadership level is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific rank for the leadership level.
     *
     * @param  int  $rank  The hierarchical rank (1 = highest authority)
     */
    public function rank(int $rank): static
    {
        return $this->state(fn (array $attributes) => [
            'rank' => $rank,
        ]);
    }

    /**
     * Set a specific name for the leadership level.
     *
     * @param  string  $name  The display name of the level
     */
    public function named(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }

    /**
     * Create a leadership level with specific color.
     *
     * @param  string  $color  Hex color code (e.g., '#FF5733')
     */
    public function colored(string $color): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => $color,
        ]);
    }
}
