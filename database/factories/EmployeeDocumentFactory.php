<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
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
            'uploaded_by' => \App\Models\User::factory(),
            'title' => fake()->sentence(3),
            'document_type' => fake()->randomElement([
                'contract',
                'id_document',
                'certificate',
                'banking_details',
                'medical_certificate',
                'work_permit',
                'background_check',
                'other',
            ]),
            'file_name' => fake()->word().'.pdf',
            'file_path' => 'employee_documents/'.fake()->uuid().'.enc',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(10000, 5000000), // 10KB to 5MB
            'expiry_date' => fake()->optional()->dateTimeBetween('now', '+2 years'),
            'status' => 'valid',
            'visible_to_employee' => fake()->boolean(70), // 70% visible to employee
        ];
    }

    /**
     * Contract document.
     */
    public function contract(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type' => 'contract',
            'file_name' => 'Arbeitsvertrag_'.fake()->lastName().'.pdf',
            'visible_to_employee' => true,
        ]);
    }

    /**
     * Certificate document.
     */
    public function certificate(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type' => 'certificate',
            'visible_to_employee' => true,
            'expiry_date' => fake()->dateTimeBetween('+1 year', '+3 years'),
        ]);
    }
}
