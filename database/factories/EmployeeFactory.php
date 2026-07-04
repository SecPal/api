<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Employee $employee): void {
            if ($employee->addresses()->exists()) {
                return;
            }

            EmployeeAddress::factory()->current()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => fake()->streetName(),
                'house_number' => fake()->buildingNumber(),
                'postal_code' => fake()->postcode(),
                'city' => fake()->randomElement(['Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt', 'Stuttgart', 'Düsseldorf', 'Dortmund', 'Essen', 'Leipzig']),
                'country' => 'DE',
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get the most recently created tenant from the current test context.
        // Don't cache between tests (RefreshDatabase clears everything)
        $tenant = TenantKey::query()->latest('id')->first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $dateOfBirth = fake()->date('Y-m-d', '-25 years');
        $germanCities = ['Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt', 'Stuttgart', 'Düsseldorf', 'Dortmund', 'Essen', 'Leipzig'];

        // Use faker unique() for test employee numbers
        // Format: EMP-NNNN with unique sequence to avoid collisions in parallel tests
        $employeeNumber = sprintf(
            'EMP-%04d',
            fake()->unique()->numberBetween(1, 9999)
        );

        return [
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => OrganizationalUnit::factory(),
            'employee_number' => $employeeNumber,
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

            'tax_id' => fake()->numerify('##########'), // 11-digit German tax ID
            'social_security_number' => fake()->numerify('## ########## ####'), // German format: 12 digits with spaces
            'hire_date' => fake()->date('Y-m-d', '-1 year'),
            'weekly_hours' => fake()->randomElement([20.00, 30.00, 40.00]),
            'monthly_hours' => 173.00, // Standard 173h/month for security industry
            'hourly_rate' => fake()->randomFloat(2, 12.00, 25.00),
            'contract_type' => fake()->randomElement(['full_time', 'part_time', 'minijob', 'freelance']),
            'status' => Employee::STATUS_ACTIVE,
            'onboarding_completed_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACTIVE,
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
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_INVITED,
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
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACTIVE,
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
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACTIVE,
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
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACTIVE,
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
        ])->afterCreating(function (Employee $employee): void {
            $employee->addresses()->delete();
            EmployeeAddress::factory()->current()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => fake()->streetName(),
                'postal_code' => fake()->postcode(),
                'city' => fake()->city(),
                'country' => 'DE',
            ]);
        });
    }

    /**
     * Indicate that the employee has complete BewachV § 16 compliance data.
     */
    public function withCompleteBewachvData(): static
    {
        $germanCities = ['Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt', 'Stuttgart', 'Düsseldorf'];
        $europeanCountries = ['DE', 'PL', 'RO', 'IT', 'GR', 'BG'];

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
            'nationalities' => fake()->randomElement([['DE'], ['DE', 'PL'], ['DE', 'IT']]), // Dual citizenship

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
        ])->afterCreating(function (Employee $employee) use ($germanCities): void {
            $employee->addresses()->delete();

            EmployeeAddress::factory()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => fake()->streetName(),
                'house_number' => fake()->buildingNumber(),
                'postal_code' => fake()->postcode(),
                'city' => fake()->city(),
                'country' => 'DE',
                'supplement' => fake()->optional(0.2)->randomElement(['Hinterhof', 'Seiteneingang', 'c/o Müller']),
                'resided_from' => '2019-01-01',
                'resided_until' => '2023-12-31',
            ]);

            EmployeeAddress::factory()->current()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => fake()->streetName(),
                'house_number' => fake()->buildingNumber(),
                'postal_code' => fake()->postcode(),
                'city' => fake()->randomElement($germanCities),
                'supplement' => fake()->optional(0.2)->randomElement(['Hinterhof', 'Seiteneingang', 'c/o Müller']),
                'country' => 'DE',
                'resided_from' => '2024-01-01',
            ]);
        });
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
        return $this->afterCreating(function (Employee $employee): void {
            $employee->addresses()->delete();

            EmployeeAddress::factory()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => fake()->streetName(),
                'city' => fake()->city(),
                'postal_code' => fake()->postcode(),
                'country' => 'DE',
                'resided_from' => '2019-01-01',
                'resided_until' => '2022-06-30',
            ]);

            EmployeeAddress::factory()->current()->create([
                'employee_id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'street' => fake()->streetName(),
                'house_number' => fake()->buildingNumber(),
                'postal_code' => fake()->postcode(),
                'city' => fake()->city(),
                'country' => 'DE',
                'resided_from' => '2022-07-01',
            ]);
        });
    }

    /**
     * Indicate that the employee has security personnel qualifications (BewachV).
     */
    public function withSecurityQualifications(): static
    {
        return $this->state(fn (array $attributes) => [
            'sachkunde_type' => fake()->randomElement(['§34a_old', '§34a_new', 'none']),
            'sachkunde_certificate' => fake()->bothify('SK-#######'),
            'sachkunde_issued_date' => fake()->date('Y-m-d', '-2 years'),
        ]);
    }

    /**
     * Indicate that the employee is a non-EU worker with a valid work permit.
     */
    public function withNonEuWorkPermit(): static
    {
        $permitType = fake()->randomElement([
            Employee::WORK_PERMIT_TYPE_TEMPORARY,
            Employee::WORK_PERMIT_TYPE_PERMANENT,
            Employee::WORK_PERMIT_TYPE_BLUE_CARD,
            Employee::WORK_PERMIT_TYPE_SEASONAL,
            Employee::WORK_PERMIT_TYPE_STUDENT,
        ]);

        return $this->state(function (array $attributes) use ($permitType): array {
            $nationality = fake()->randomElement(['TR', 'RS', 'UA', 'IN']);

            return [
                'birth_country' => $nationality,
                'nationalities' => [$nationality],
                'work_permit_type' => $permitType,
                'work_permit_number' => fake()->bothify('WP-######'),
                'work_permit_expiry' => $permitType === Employee::WORK_PERMIT_TYPE_PERMANENT
                    ? null
                    : fake()->dateTimeBetween('+2 months', '+2 years'),
                'work_permit_copy_path' => 'work_permits/'.fake()->uuid().'.pdf',
                'work_permit_issued_by' => fake()->randomElement([
                    'Auslaenderbehoerde Berlin',
                    'Auslaenderbehoerde Hamburg',
                    'Auslaenderbehoerde Muenchen',
                ]),
            ];
        });
    }

    /**
     * Indicate that the employee has a work permit expiring soon.
     */
    public function withExpiringWorkPermit(): static
    {
        return $this->withNonEuWorkPermit()->state(fn (array $attributes) => [
            'work_permit_type' => Employee::WORK_PERMIT_TYPE_TEMPORARY,
            'work_permit_expiry' => fake()->dateTimeBetween('+1 day', '+30 days'),
        ]);
    }

    /**
     * Indicate that the employee has tracked compliance certifications.
     */
    public function withComplianceCertifications(): static
    {
        return $this->state(fn (array $attributes) => [
            'firearms_license_number' => fake()->bothify('WL-######'),
            'firearms_license_expiry' => fake()->dateTimeBetween('+2 months', '+2 years'),
            'firearms_license_issued_by' => fake()->randomElement([
                'Polizeipraesidium Berlin',
                'Polizeipraesidium Hamburg',
                'Polizeipraesidium Muenchen',
            ]),
            'first_aid_cert_number' => fake()->bothify('FA-#####'),
            'first_aid_cert_date' => fake()->dateTimeBetween('-2 years', '-6 months'),
            'first_aid_cert_expiry' => fake()->dateTimeBetween('+2 months', '+2 years'),
            'fire_safety_cert_date' => fake()->dateTimeBetween('-2 years', '-6 months'),
            'fire_safety_cert_expiry' => fake()->dateTimeBetween('+2 months', '+2 years'),
            'evacuation_cert_date' => fake()->dateTimeBetween('-2 years', '-6 months'),
            'evacuation_cert_expiry' => fake()->dateTimeBetween('+2 months', '+2 years'),
            'additional_certifications' => [
                [
                    'name' => 'Site Access Badge',
                    'number' => fake()->bothify('BADGE-##'),
                    'issued_date' => fake()->date('Y-m-d', '-6 months'),
                    'expiry_date' => fake()->date('Y-m-d', '+1 year'),
                    'issuer' => 'Customer Security',
                ],
            ],
        ]);
    }

    /**
     * Indicate that the employee has compliance certifications expiring within 30 days.
     */
    public function withExpiringComplianceCertifications(): static
    {
        return $this->withComplianceCertifications()->state(fn (array $attributes) => [
            'firearms_license_expiry' => fake()->dateTimeBetween('+1 day', '+15 days'),
            'first_aid_cert_expiry' => fake()->dateTimeBetween('+1 day', '+20 days'),
            'fire_safety_cert_expiry' => fake()->dateTimeBetween('+40 days', '+80 days'),
            'evacuation_cert_expiry' => fake()->dateTimeBetween('-10 days', '-1 day'),
            'additional_certifications' => [
                [
                    'name' => 'Site Access Badge',
                    'number' => fake()->bothify('BADGE-##'),
                    'issued_date' => fake()->dateTimeBetween('-6 months', '-1 month')->format('Y-m-d'),
                    'expiry_date' => fake()->dateTimeBetween('+1 day', '+5 days')->format('Y-m-d'),
                    'issuer' => 'Customer Security',
                ],
            ],
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
