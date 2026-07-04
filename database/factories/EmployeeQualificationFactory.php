<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\Qualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmployeeQualification>
 */
class EmployeeQualificationFactory extends Factory
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
            'qualification_id' => Qualification::factory(),
            'obtained_date' => fake()->dateTimeBetween('-2 years', 'now'),
            'expiry_date' => fake()->optional(0.7)->dateTimeBetween('now', '+3 years'),
            'certificate_number' => fake()->optional()->bothify('CERT-####-????'),
            'issuing_authority' => fake()->optional()->company(),
            'notes' => fake()->optional()->sentence(),
            'status' => EmployeeQualification::STATUS_ACTIVE,
        ];
    }

    /**
     * Indicate that the qualification is expiring soon.
     */
    public function expiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => fake()->dateTimeBetween('now', '+30 days'),
            'status' => EmployeeQualification::STATUS_EXPIRING,
        ]);
    }

    /**
     * Indicate that the qualification has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => fake()->dateTimeBetween('-1 year', '-1 day'),
            'status' => EmployeeQualification::STATUS_EXPIRED,
        ]);
    }

    /**
     * Indicate that the qualification is valid (active).
     */
    public function valid(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => fake()->dateTimeBetween('+3 months', '+3 years'),
            'status' => EmployeeQualification::STATUS_ACTIVE,
        ]);
    }
}
