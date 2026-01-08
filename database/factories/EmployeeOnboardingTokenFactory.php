<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeOnboardingToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmployeeOnboardingToken>
 */
class EmployeeOnboardingTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Default token expires in 7 days
        return [
            'employee_id' => Employee::factory(),
            'token' => Hash::make('test-token-' . fake()->uuid()),
            'expires_at' => now()->addDays(7),
            'completed_at' => null,
            'completed_from_ip' => null,
            'completed_user_agent' => null,
        ];
    }

    /**
     * Indicate that the token is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the token has been completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
            'completed_from_ip' => fake()->ipv4(),
            'completed_user_agent' => fake()->userAgent(),
        ]);
    }
}
