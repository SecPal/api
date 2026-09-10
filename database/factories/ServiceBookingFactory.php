<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingUnit;
use App\Enums\InvoiceState;
use App\Enums\ServiceBookingStatus;
use App\Models\Contract;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceBooking> */
class ServiceBookingFactory extends Factory
{
    protected $model = ServiceBooking::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'contract_id' => function (array $attributes): string {
                $tenantId = $attributes['tenant_id'] ?? null;
                if (! is_int($tenantId) && ! is_string($tenantId)) {
                    throw new \LogicException('Service booking factories require a tenant identity.');
                }

                return Contract::factory()->forTenant($tenantId)->create()->id;
            },
            'service_date' => today(),
            'quantity' => sprintf(
                '%d.%04d',
                fake()->numberBetween(1, 100),
                fake()->numberBetween(0, 9999),
            ),
            'billing_unit' => fake()->randomElement(BillingUnit::cases()),
            'unit_price' => sprintf(
                '%d.%04d',
                fake()->numberBetween(0, 10000),
                fake()->numberBetween(0, 9999),
            ),
            'currency_code' => function (array $attributes): string {
                $contractId = $attributes['contract_id'] ?? null;
                if (! is_string($contractId)) {
                    throw new \LogicException('Service booking factories require a contract identity.');
                }

                return Contract::query()->findOrFail($contractId)->currency_code;
            },
            'invoice_state' => InvoiceState::Unbilled,
            'invoiced_at' => null,
            'status' => ServiceBookingStatus::Active,
            'retired_at' => null,
        ];
    }

    public function forTenant(int|string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId]);
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'currency_code' => $contract->currency_code,
        ]);
    }

    public function invoiced(): static
    {
        return $this->state(fn (): array => [
            'invoice_state' => InvoiceState::Invoiced,
            'invoiced_at' => now(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => ServiceBookingStatus::Retired,
            'retired_at' => now(),
        ]);
    }
}
