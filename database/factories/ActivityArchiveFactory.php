<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityArchive;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ActivityArchive factory for testing.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActivityArchive>
 */
class ActivityArchiveFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ActivityArchive>
     */
    protected $model = ActivityArchive::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // ID omitted - will be set explicitly or auto-incremented
            'tenant_id' => TenantKey::factory(),
            'log_name' => fake()->randomElement(['security', 'authentication', 'rbac_changes']),
            'created_at' => fake()->dateTimeBetween('-5 years', '-3 years'),
            'event_hash' => hash('sha256', fake()->uuid()),
            'previous_hash' => fake()->optional()->passthrough(hash('sha256', fake()->uuid())),
            'merkle_root' => fake()->optional()->passthrough(hash('sha256', fake()->uuid())),
            'merkle_batch_id' => fake()->optional()->numberBetween(1, 10000),
        ];
    }

    /**
     * Indicate that the archive is a genesis log (no predecessor).
     */
    public function genesis(): static
    {
        return $this->state(fn (array $attributes) => [
            'previous_hash' => null,
        ]);
    }

    /**
     * Indicate that the archive is from a specific log category.
     */
    public function ofLog(string $logName): static
    {
        return $this->state(fn (array $attributes) => [
            'log_name' => $logName,
        ]);
    }

    /**
     * Indicate that the archive is from a specific date.
     */
    public function createdAt(\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $date,
        ]);
    }
}
