<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Factories;

use App\Models\Customer;
use App\Models\OrganizationalUnit;
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
        // Get or create the most recently created tenant for testing.
        $tenant = TenantKey::query()->latest('id')->first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        // German company name suffixes
        /** @var array<int, string> $companyTypes */
        $companyTypes = ['GmbH', 'AG', 'GmbH & Co. KG', 'KG', 'e.K.', 'UG'];

        /** @var string $companyType */
        $companyType = fake()->randomElement($companyTypes);

        // Use faker unique() for test customer numbers instead of Customer::generateCustomerNumber()
        // Trade-off: Fast test execution + parallel test isolation vs production parity
        // - Customer::generateCustomerNumber() is production-accurate but queries DB on every factory call
        // - faker unique() is fast, isolated, thread-safe for parallel tests (9999 limit acceptable for tests)
        // Format: KD-YYYY-NNNN with random sequence to avoid collisions in parallel tests
        $customerNumber = sprintf(
            'KD-%d-%04d',
            (int) date('Y'),
            fake()->unique()->numberBetween(1, 9999)
        );

        return [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => function (array $attributes) use ($tenant): string {
                $tenantId = $attributes['tenant_id'] ?? $tenant->id;

                if (! is_int($tenantId) && ! is_string($tenantId)) {
                    throw new \InvalidArgumentException('CustomerFactory tenant_id must be an integer or string.');
                }

                return OrganizationalUnit::factory()
                    ->forTenant((string) $tenantId)
                    ->create(['is_legal_entity' => true])
                    ->id;
            },
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
