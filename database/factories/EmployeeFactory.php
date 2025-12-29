<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get existing tenant from first test (created by setUp)
        // Don't cache between tests (RefreshDatabase clears everything)
        $tenant = TenantKey::first();
        if (! $tenant) {
            // Ensure KEK exists for testing
            if (! file_exists(TenantKey::getKekPath())) {
                TenantKey::generateKek();
            }
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $dateOfBirth = fake()->date('Y-m-d', '-25 years');

        return [
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => OrganizationalUnit::factory(),
            'employee_number' => strtoupper(fake()->bothify('EMP-####')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'tax_id' => fake()->numerify('##########'), // 11-digit German tax ID
            'social_security_number' => fake()->numerify('## ########## ####'), // German format: 12 digits with spaces
            'hire_date' => fake()->date('Y-m-d', '-1 year'),
            'weekly_hours' => fake()->randomElement([20.00, 30.00, 40.00]),
            'monthly_hours' => 173.00, // Standard 173h/month for security industry
            'hourly_rate' => fake()->randomFloat(2, 12.00, 25.00),
            'contract_type' => fake()->randomElement(['full_time', 'part_time', 'minijob', 'freelance']),
            'status' => Employee::STATUS_ACTIVE,
            'onboarding_completed_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'management_level' => 0, // Default: non-management (0=Guards, 1-255=Management)
            'user_account_active' => false, // Explicit default to prevent dirty flag on updates
            // Don't set user_id by default - let tests control this
            // or use withUser() state
        ];
    }

    /**
     * Indicate that the employee is in pre-contract status.
     * Note: Observer will automatically create user account when saved.
     */
    public function preContract(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed_at' => null,
            'hire_date' => null,
            'user_id' => null, // Observer will create user
            'user_account_active' => false,
        ]);
    }

    /**
     * Indicate that the employee is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Employee::STATUS_ACTIVE,
            'onboarding_completed_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }

    /**
     * Indicate that the employee is on leave.
     */
    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Employee::STATUS_ON_LEAVE,
            'onboarding_completed_at' => fake()->dateTimeBetween('-1 year', '-6 months'),
        ]);
    }

    /**
     * Indicate that the employee is terminated.
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Employee::STATUS_TERMINATED,
            'termination_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'onboarding_completed_at' => fake()->dateTimeBetween('-2 years', '-1 year'),
        ]);
    }

    /**
     * Indicate that the employee has security personnel qualifications (BewachV).
     */
    public function withSecurityQualifications(): static
    {
        return $this->state(fn (array $attributes) => [
            'sachkunde_type' => fake()->randomElement(['§34a_old', '§34a_new', 'none']),
            'sachkunde_certificate_number' => fake()->bothify('SK-#######'),
            'sachkunde_issued_date' => fake()->date('Y-m-d', '-2 years'),
            'work_permit' => fake()->boolean(80), // 80% have work permit
            'work_permit_expiry' => fake()->date('Y-m-d', '+1 year'),
        ]);
    }

    /**
     * Indicate that the employee has a linked user account.
     */
    public function withUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => \App\Models\User::factory(),
        ]);
    }
}
