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
        $germanCities = ['Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt', 'Stuttgart', 'Düsseldorf', 'Dortmund', 'Essen', 'Leipzig'];

        return [
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => OrganizationalUnit::factory(),
            'employee_number' => strtoupper(fake()->bothify('EMP-####')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),

            // BewachV § 16: Identity Data
            'gender' => fake()->randomElement(['male', 'female', 'diverse']),
            'birth_city' => fake()->randomElement($germanCities),
            'birth_country' => 'DE', // Default to Germany
            'nationalities' => ['DE'], // Default German nationality

            // BewachV § 16: Structured Address
            'address_street' => fake()->streetName(),
            'address_house_number' => fake()->buildingNumber(),
            'address_postal_code' => fake()->postcode(),
            'address_city' => fake()->randomElement($germanCities),
            'address_country' => 'DE',

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
            'last_working_day' => fake()->dateTimeBetween('-3 months', 'now'),
            'onboarding_completed_at' => fake()->dateTimeBetween('-2 years', '-1 year'),
            // Note: employment_end_date and retention_period_end are auto-calculated by Observer
        ]);
    }

    /**
     * Indicate that the employee has BWR registration (Bewacherregister).
     */
    public function withBwrRegistration(): static
    {
        $status = fake()->randomElement(['active', 'pending', 'suspended', 'revoked']);

        return $this->state(fn (array $attributes) => [
            'bwr_id' => fake()->numerify('#######'), // Exactly 7 digits (0000000-9999999)
            'bwr_status' => $status,
            'bwr_registered_at' => $status === 'active' ? fake()->dateTimeBetween('-2 years', 'now') : null,
            'bwr_submission_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'gender' => fake()->randomElement(['male', 'female', 'diverse']), // MANDATORY for BWR
            'address_street' => fake()->streetName(), // MANDATORY for BWR
            'address_postal_code' => fake()->postcode(), // MANDATORY for BWR
            'address_city' => fake()->city(), // MANDATORY for BWR
        ]);
    }

    /**
     * Indicate that the employee has complete BewachV § 16 compliance data.
     */
    public function withCompleteBewachvData(): static
    {
        $germanCities = ['Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt', 'Stuttgart', 'Düsseldorf'];
        $europeanCountries = ['DE', 'PL', 'RO', 'TR', 'IT', 'GR', 'BG'];

        return $this->state(fn (array $attributes) => [
            // BWR Registration
            'bwr_id' => fake()->numerify('#######'),
            'bwr_status' => 'active',
            'bwr_registered_at' => fake()->dateTimeBetween('-2 years', '-1 year'),
            'bwr_submission_date' => fake()->dateTimeBetween('-2 years', '-1 year'),

            // Identity
            'gender' => fake()->randomElement(['male', 'female', 'diverse']),
            'birth_name' => fake()->optional(0.3)->lastName(), // 30% have different birth name
            'previous_names' => fake()->optional(0.2)->randomElements([fake()->lastName(), fake()->lastName()], 1), // 20% have previous names
            'birth_city' => fake()->randomElement($germanCities),
            'birth_country' => fake()->randomElement($europeanCountries),
            'nationalities' => fake()->randomElement([['DE'], ['DE', 'PL'], ['DE', 'TR']]), // Dual citizenship

            // Structured Address
            'address_street' => fake()->streetName(),
            'address_house_number' => fake()->buildingNumber(),
            'address_postal_code' => fake()->postcode(),
            'address_city' => fake()->randomElement($germanCities),
            'address_supplement' => fake()->optional(0.2)->randomElement(['Hinterhof', 'Seiteneingang', 'c/o Müller']),
            'address_country' => 'DE',
            'address_state' => fake()->randomElement(['NRW', 'BY', 'BW', 'BE', 'HH']),

            // Address History (last 5 years) - 1-2 previous addresses
            'address_history' => fake()->randomElements([
                [
                    'from' => '2019-01-01',
                    'to' => '2021-06-30',
                    'street' => fake()->streetName(),
                    'city' => fake()->city(),
                    'postal_code' => fake()->postcode(),
                    'country' => 'DE',
                ],
                [
                    'from' => '2021-07-01',
                    'to' => '2023-12-31',
                    'street' => fake()->streetName(),
                    'city' => fake()->city(),
                    'postal_code' => fake()->postcode(),
                    'country' => 'DE',
                ],
            ], fake()->numberBetween(0, 2)),

            // Intended Activities (BewachV §34a work types)
            'intended_activities' => fake()->randomElements([
                'Bewachung_Objektschutz',
                'Bewachung_Personenschutz',
                'Bewachung_Veranstaltungsschutz',
                'Bewachung_Citystreife',
                'Kontrollgaenge',
            ], fake()->numberBetween(1, 3)),

            // ID Document
            'id_document_type' => fake()->randomElement(['passport', 'id_card', 'residence_permit']),
            'id_document_number' => fake()->bothify('??########'),
            'id_document_expiry' => fake()->dateTimeBetween('now', '+5 years'),

            // Sachkunde (IHK qualification)
            'sachkunde_ihk_number' => fake()->bothify('IHK-#######'),
            'sachkunde_exam_date' => fake()->dateTimeBetween('-3 years', '-1 year'),
            'sachkunde_issued_date' => fake()->dateTimeBetween('-3 years', '-1 year'),
        ]);
    }

    /**
     * Indicate that the employee has dual citizenship.
     */
    public function withDualCitizenship(): static
    {
        $countries = [['DE', 'PL'], ['DE', 'TR'], ['DE', 'IT'], ['DE', 'GR']];

        return $this->state(fn (array $attributes) => [
            'nationalities' => fake()->randomElement($countries),
            'birth_country' => fake()->randomElement(['PL', 'TR', 'IT', 'GR']),
        ]);
    }

    /**
     * Indicate that the employee has address history.
     */
    public function withAddressHistory(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_history' => [
                [
                    'from' => '2019-01-01',
                    'to' => '2022-06-30',
                    'street' => fake()->streetName(),
                    'city' => fake()->city(),
                    'postal_code' => fake()->postcode(),
                    'country' => 'DE',
                ],
            ],
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
