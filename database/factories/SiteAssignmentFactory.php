<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for generating SiteAssignment model instances with test data.
 *
 * @extends Factory<SiteAssignment>
 */
class SiteAssignmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SiteAssignment>
     */
    protected $model = SiteAssignment::class;

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

        return [
            'tenant_id' => $tenant->id,
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'role' => $this->faker->randomElement([
                'Account Manager',
                'Site Manager',
                'Operations Lead',
                'Quality Manager',
                'Objektleiter',
                'Einsatzleiter',
                'Kundenbetreuer',
            ]),
            'valid_from' => null,
            'valid_until' => null,
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * Indicate that the assignment has a validity period.
     */
    public function withValidityPeriod(): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'valid_until' => $this->faker->dateTimeBetween('now', '+1 year'),
        ]);
    }

    /**
     * Indicate that the assignment is currently active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->addDays(10),
        ]);
    }

    /**
     * Indicate that the assignment has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => now()->subMonths(6),
            'valid_until' => now()->subDays(10),
        ]);
    }

    /**
     * Indicate that the assignment starts in the future.
     */
    public function future(): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => now()->addDays(10),
            'valid_until' => now()->addMonths(6),
        ]);
    }

    /**
     * Indicate that the assignment is indefinite (no end date).
     */
    public function indefinite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => now()->subDays(30),
            'valid_until' => null,
        ]);
    }
}
