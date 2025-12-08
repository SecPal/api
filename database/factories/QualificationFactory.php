<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Qualification;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Qualification>
 */
class QualificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get existing tenant from first test (created by setUp)
        $tenant = TenantKey::first();
        if (! $tenant) {
            if (! file_exists(TenantKey::getKekPath())) {
                TenantKey::generateKek();
            }
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        $category = fake()->randomElement([
            'bewachv_34a',
            'first_aid',
            'fire_safety',
            'safety_officer',
            'specialized',
            'education',
            'custom',
        ]);

        return [
            'tenant_id' => $tenant->id,
            'name' => fake()->randomElement([
                '§34a Sachkundeprüfung',
                'Erste Hilfe Kurs',
                'Brandschutzhelfer',
                'Deeskalationstraining',
                'Waffensachkunde',
            ]),
            'description' => fake()->optional()->sentence(),
            'category' => $category,
            'requires_renewal' => fake()->boolean(60), // 60% require renewal
            'renewal_period_months' => fake()->boolean(60) ? fake()->randomElement([12, 24, 36]) : null,
            'is_mandatory' => fake()->boolean(40), // 40% are mandatory
            'is_system_qualification' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * System-wide qualification (tenant_id = null).
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'is_system_qualification' => true,
        ]);
    }

    /**
     * BewachV §34a qualification.
     */
    public function bewachv(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => '§34a Sachkundeprüfung (IHK)',
            'category' => 'bewachv_34a',
            'requires_renewal' => true,
            'renewal_period_months' => 60,
            'is_mandatory' => true,
            'is_system_qualification' => true,
        ]);
    }

    /**
     * First aid qualification.
     */
    public function firstAid(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Erste Hilfe Kurs',
            'category' => 'first_aid',
            'requires_renewal' => true,
            'renewal_period_months' => 24,
            'is_mandatory' => true,
            'is_system_qualification' => true,
        ]);
    }
}
