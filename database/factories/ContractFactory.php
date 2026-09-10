<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingUnit;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contract> */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'customer_id' => function (array $attributes): string {
                $tenantId = $attributes['tenant_id'] ?? null;
                if (! is_int($tenantId) && ! is_string($tenantId)) {
                    throw new \LogicException('Contract factories require a tenant identity.');
                }

                return Customer::factory()->forTenant((int) $tenantId)->create()->id;
            },
            'type' => fake()->randomElement(ContractType::cases()),
            'status' => ContractStatus::Active,
            'starts_on' => today(),
            'ends_on' => null,
            'billing_unit' => fake()->randomElement(BillingUnit::cases()),
            'unit_price' => sprintf(
                '%d.%04d',
                fake()->numberBetween(0, 10000),
                fake()->numberBetween(0, 9999),
            ),
            'currency_code' => 'EUR',
            'retired_at' => null,
        ];
    }

    public function forTenant(int|string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => ContractStatus::Retired,
            'retired_at' => now(),
        ]);
    }
}
