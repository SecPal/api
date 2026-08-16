<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for generating Site model instances with realistic test data.
 *
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Site>
     */
    protected $model = Site::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Site $site): void {
            $customer = Customer::query()->findOrFail($site->customer_id);
            $site->tenant_id = $customer->tenant_id;
            $site->legal_entity_id = $customer->legal_entity_id;

            $establishment = Establishment::query()->find($site->establishment_id);
            if (! $establishment
                || $establishment->tenant_id !== $customer->tenant_id
                || $establishment->legal_entity_id !== $customer->legal_entity_id) {
                $establishment = Establishment::factory()->create([
                    'tenant_id' => $customer->tenant_id,
                    'legal_entity_id' => $customer->legal_entity_id,
                ]);
                $site->establishment_id = $establishment->id;
            }

            $customerEstablishment = CustomerEstablishment::withTrashed()->firstOrCreate([
                'tenant_id' => $site->tenant_id,
                'legal_entity_id' => $site->legal_entity_id,
                'customer_id' => $site->customer_id,
                'establishment_id' => $site->establishment_id,
            ]);

            if ($customerEstablishment->trashed()) {
                $customerEstablishment->restore();
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * Generates realistic German site data including:
     * - Site names (e.g., "Flughafen Terminal 1", "Einkaufszentrum City")
     * - German addresses with GPS coordinates
     * - On-site contact information
     * - Access instructions
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

        // Use faker unique() for test site numbers instead of Site::generateSiteNumber()
        // Trade-off: Fast test execution + parallel test isolation vs production parity
        // - Site::generateSiteNumber() is production-accurate but queries DB on every factory call
        // - faker unique() is fast, isolated, thread-safe for parallel tests (9999 limit acceptable for tests)
        // Format: OBJ-YYYY-NNNN with random sequence to avoid collisions in parallel tests
        $siteNumber = sprintf(
            'OBJ-%d-%04d',
            (int) date('Y'),
            fake()->unique()->numberBetween(1, 9999)
        );

        // German site types
        $siteTypes = [
            'Flughafen Terminal',
            'Einkaufszentrum',
            'Bürogebäude',
            'Industriegelände',
            'Krankenhaus',
            'Universität Campus',
            'Bahnhof',
            'Parkhaus',
            'Messehalle',
            'Hotel',
        ];

        // German cities with approximate coordinates
        $cities = [
            ['name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050],
            ['name' => 'München', 'lat' => 48.1351, 'lng' => 11.5820],
            ['name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937],
            ['name' => 'Frankfurt am Main', 'lat' => 50.1109, 'lng' => 8.6821],
            ['name' => 'Köln', 'lat' => 50.9375, 'lng' => 6.9603],
            ['name' => 'Stuttgart', 'lat' => 48.7758, 'lng' => 9.1829],
            ['name' => 'Düsseldorf', 'lat' => 51.2277, 'lng' => 6.7735],
        ];

        /** @var array{name: string, lat: float, lng: float} $city */
        $city = fake()->randomElement($cities);

        /** @var string $siteType */
        $siteType = fake()->randomElement($siteTypes);

        $legalEntity = LegalEntity::factory()->forTenant($tenant->id)->create();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $legalEntity->id,
        ]);
        $establishment = Establishment::factory()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $legalEntity->id,
        ]);

        return [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'legal_entity_id' => $legalEntity->id,
            'establishment_id' => $establishment->id,
            'site_number' => $siteNumber,
            'name' => $siteType.' '.fake()->numberBetween(1, 5),
            'type' => 'permanent',
            'address' => [
                'street' => fake()->streetAddress(),
                'city' => $city['name'],
                'postal_code' => (string) fake()->postcode(),
                'country' => 'DE',
                'lat' => $city['lat'] + fake()->randomFloat(4, -0.1, 0.1),
                'lng' => $city['lng'] + fake()->randomFloat(4, -0.1, 0.1),
            ],
            'contact' => [
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'phone' => fake()->phoneNumber(),
                'position' => fake()->randomElement([
                    'Objektleiter',
                    'Facility Manager',
                    'Hausmeister',
                    'Empfangsmitarbeiter',
                    'Sicherheitsbeauftragter',
                ]),
            ],
            'access_instructions' => fake()->optional(0.5)->paragraph(),
            'notes' => fake()->optional(0.3)->paragraph(),
            'metadata' => null,
            'is_active' => true,
            'valid_from' => null,
            'valid_until' => null,
        ];
    }

    /**
     * Indicate that the site is temporary.
     */
    public function temporary(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'temporary',
            'valid_from' => now()->subDays(fake()->numberBetween(1, 30)),
            'valid_until' => now()->addDays(fake()->numberBetween(30, 180)),
        ]);
    }

    /**
     * Indicate that the site is permanent.
     */
    public function permanent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'permanent',
            'valid_from' => null,
            'valid_until' => null,
        ]);
    }

    /**
     * Indicate that the site is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the site has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'temporary',
            'valid_from' => now()->subMonths(6),
            'valid_until' => now()->subDays(10),
        ]);
    }

    /**
     * Configure the factory with a specific customer.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(function () use ($customer): array {
            $establishment = Establishment::factory()->create([
                'tenant_id' => $customer->tenant_id,
                'legal_entity_id' => $customer->legal_entity_id,
            ]);

            return [
                'customer_id' => $customer->id,
                'tenant_id' => $customer->tenant_id,
                'legal_entity_id' => $customer->legal_entity_id,
                'establishment_id' => $establishment->id,
            ];
        });
    }

    /**
     * Configure the factory with a specific establishment.
     */
    public function forEstablishment(Establishment $establishment): static
    {
        return $this->state(function () use ($establishment): array {
            $customer = Customer::factory()->create([
                'tenant_id' => $establishment->tenant_id,
                'legal_entity_id' => $establishment->legal_entity_id,
            ]);

            return [
                'customer_id' => $customer->id,
                'tenant_id' => $establishment->tenant_id,
                'legal_entity_id' => $establishment->legal_entity_id,
                'establishment_id' => $establishment->id,
            ];
        });
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
     * Configure the factory without access instructions.
     */
    public function withoutAccessInstructions(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_instructions' => null,
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
     * Configure the factory with a specific validity period.
     */
    public function withValidityPeriod(\DateTimeInterface $from, \DateTimeInterface $until): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => $from,
            'valid_until' => $until,
        ]);
    }
}
