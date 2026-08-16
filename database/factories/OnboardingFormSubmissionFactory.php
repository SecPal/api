<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OnboardingFormTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OnboardingFormSubmission>
 */
class OnboardingFormSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'form_template_id' => OnboardingFormTemplate::factory(),
            'form_data' => [
                'field_1' => fake()->sentence(),
                'field_2' => fake()->paragraph(),
            ],
            'status' => 'draft',
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }

    /**
     * Indicate that the submission is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'submitted_at' => now()->subDays(1),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => fake()->sentence(),
        ]);
    }

    /**
     * Indicate that the submission is rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'submitted_at' => now()->subDays(1),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => fake()->sentence(),
        ]);
    }

    /**
     * Indicate that the submission is submitted (awaiting review).
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'submitted_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ]);
    }
}
