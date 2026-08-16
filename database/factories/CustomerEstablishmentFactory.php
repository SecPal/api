<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerEstablishment> */
class CustomerEstablishmentFactory extends Factory
{
    protected $model = CustomerEstablishment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $customer = Customer::factory()->create();
        $establishment = Establishment::factory()->create([
            'tenant_id' => $customer->tenant_id,
            'legal_entity_id' => $customer->legal_entity_id,
        ]);

        return [
            'tenant_id' => $customer->tenant_id,
            'legal_entity_id' => $customer->legal_entity_id,
            'customer_id' => $customer->id,
            'establishment_id' => $establishment->id,
            'contact_name_plain' => fake()->optional()->name(),
            'phone_plain' => fake()->optional()->phoneNumber(),
            'email_plain' => fake()->optional()->safeEmail(),
            'comments_plain' => fake()->optional()->sentence(),
        ];
    }
}
