<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Customer;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for generating Customer model instances with realistic test data.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Customer>
     */
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * Generates realistic German customer data including:
     * - Company names (e.g., "Mustermann GmbH", "Schmidt Security AG")
     * - German billing addresses with postal codes
     * - Contact person information
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create tenant for testing
        $tenant = TenantKey::first();
        if (! $tenant) {
            if (! file_exists(TenantKey::getKekPath())) {
                TenantKey::generateKek();
            }
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        // German company name suffixes
        /** @var array<int, string> $companyTypes */
        $companyTypes = ['GmbH', 'AG', 'GmbH & Co. KG', 'KG', 'e.K.', 'UG'];

        /** @var string $companyType */
        $companyType = fake()->randomElement($companyTypes);

        // Use sequence to generate unique customer numbers per tenant for testing
        // Format: KD-YYYY-NNNN (sequence starts at 1 for each factory run)
        /** @var array<int, int> $sequence */
        static $sequence = [];
        $tenantId = $tenant->id;
        if (! isset($sequence[$tenantId])) {
            $sequence[$tenantId] = 1;
        }
        $currentSequence = $sequence[$tenantId];
        $sequence[$tenantId]++;
        $customerNumber = sprintf('KD-%d-%04d', (int) date('Y'), $currentSequence);

        return [
            'tenant_id' => $tenant->id,
            'customer_number' => $customerNumber,
            'name' => fake()->company().' '.$companyType,
            'billing_address' => [
                'street' => fake()->streetAddress(),
                'city' => fake()->city(),
                'postal_code' => (string) fake()->postcode(),
                'country' => 'DE',
            ],
            'contact' => [
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'phone' => fake()->phoneNumber(),
                'position' => fake()->randomElement([
                    'Geschäftsführer',
                    'Facility Manager',
                    'Objektleiter',
                    'Einkaufsleiter',
                    'Verwaltungsleiter',
                ]),
            ],
            'notes' => fake()->optional(0.3)->paragraph(),
            'metadata' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the customer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Configure the factory with a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Configure the factory without contact information.
     */
    public function withoutContact(): static
    {
        return $this->state(fn (array $attributes) => [
            'contact' => null,
        ]);
    }

    /**
     * Configure the factory with custom metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata,
        ]);
    }

    /**
     * Configure the factory with detailed notes.
     */
    public function withNotes(string $notes): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes,
        ]);
    }
}
