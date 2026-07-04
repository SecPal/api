<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Factories;

use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OnboardingFormTemplate>
 */
class OnboardingFormTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'name' => fake()->sentence(3),
            'template_key' => null,
            'description' => fake()->sentence(),
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'field' => ['type' => 'string'],
                ],
                'additionalProperties' => true,
            ],
            'is_required' => fake()->boolean(70),
            'is_system_template' => false,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Indicate that the template is a system template.
     */
    public function systemTemplate(): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'is_system_template' => true,
            'is_required' => true,
        ]);
    }

    /**
     * Indicate that the template is required.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }

    /**
     * Indicate that the template is optional.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => false,
        ]);
    }
}
