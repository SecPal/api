<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmployeeAddress>
 */
class EmployeeAddressFactory extends Factory
{
    protected $model = EmployeeAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'tenant_id' => fn (array $attributes) => Employee::query()->where('id', $attributes['employee_id'])->firstOrFail()->tenant_id,
            'street' => fake()->streetName(),
            'house_number' => fake()->buildingNumber(),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'supplement' => fake()->optional(0.15)->randomElement(['Hinterhof', 'c/o Test']),
            'country' => 'DE',
            'resided_from' => fake()->dateTimeBetween('-8 years', '-1 year')->format('Y-m-d'),
            'resided_until' => fake()->dateTimeBetween('-1 year', '-1 month')->format('Y-m-d'),
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'resided_from' => fake()->boolean(40) ? fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d') : null,
            'resided_until' => null,
        ]);
    }
}
