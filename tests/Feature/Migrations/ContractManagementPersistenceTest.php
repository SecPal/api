<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\BillingUnit;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InternalCostCenterStatus;
use App\Enums\InvoiceState;
use App\Enums\ServiceBookingStatus;
use App\Models\Contract;
use App\Models\CostCenterAllocation;
use App\Models\Customer;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/** @return array<string, mixed> */
function contractPersistenceRow(Customer $customer, array $overrides = []): array
{
    return array_replace([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $customer->tenant_id,
        'customer_id' => $customer->id,
        'type' => 'permanent',
        'status' => 'active',
        'starts_on' => '2026-09-01',
        'ends_on' => null,
        'billing_unit' => 'hour',
        'unit_price' => '42.5000',
        'currency_code' => 'EUR',
        'retired_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/** @return array<string, mixed> */
function serviceBookingPersistenceRow(Contract $contract, array $overrides = []): array
{
    return array_replace([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $contract->tenant_id,
        'contract_id' => $contract->id,
        'service_date' => '2026-09-05',
        'quantity' => '2.0000',
        'billing_unit' => 'hour',
        'unit_price' => '42.5000',
        'currency_code' => $contract->currency_code,
        'invoice_state' => 'unbilled',
        'invoiced_at' => null,
        'status' => 'active',
        'retired_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

test('creates the authoritative PostgreSQL contract management schema', function (): void {
    expect((int) DB::scalar('SHOW server_version_num'))->toBeGreaterThanOrEqual(180000)
        ->and(Schema::hasColumns('contracts', [
            'id', 'tenant_id', 'customer_id', 'type', 'status', 'starts_on', 'ends_on',
            'billing_unit', 'unit_price', 'currency_code', 'retired_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('service_bookings', [
            'id', 'tenant_id', 'contract_id', 'service_date', 'quantity', 'billing_unit',
            'unit_price', 'currency_code', 'total', 'invoice_state', 'invoiced_at',
            'status', 'retired_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('internal_cost_centers', [
            'id', 'tenant_id', 'code', 'name', 'status', 'inactive_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('cost_center_allocations', [
            'id', 'tenant_id', 'service_booking_id', 'internal_cost_center_id',
            'share_bps', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('service_bookings', 'shift_id'))->toBeFalse();

    $total = DB::table('information_schema.columns')
        ->whereRaw('table_schema = current_schema()')
        ->where('table_name', 'service_bookings')
        ->where('column_name', 'total')
        ->first(['data_type', 'numeric_precision', 'numeric_scale', 'is_generated']);

    expect($total)->not->toBeNull()
        ->and($total?->data_type)->toBe('numeric')
        ->and($total?->numeric_precision)->toBe(22)
        ->and($total?->numeric_scale)->toBe(2)
        ->and($total?->is_generated)->toBe('ALWAYS');
});

test('declares tenant-safe foreign keys and downstream access indexes', function (): void {
    $constraints = DB::table('pg_constraint')
        ->whereRaw('connamespace = current_schema()::regnamespace')
        ->whereIn('conname', [
            'contracts_tenant_customer_foreign',
            'service_bookings_tenant_contract_foreign',
            'cost_center_allocations_tenant_booking_foreign',
            'cost_center_allocations_tenant_center_foreign',
            'cost_center_allocations_booking_center_unique',
        ])
        ->orderBy('conname')
        ->pluck('conname')
        ->all();

    $indexes = DB::table('pg_indexes')
        ->whereRaw('schemaname = current_schema()')
        ->whereIn('indexname', [
            'contracts_tenant_customer_index',
            'contracts_customer_index',
            'contracts_tenant_status_index',
            'service_bookings_tenant_contract_index',
            'service_bookings_contract_index',
            'service_bookings_tenant_date_status_invoice_index',
            'internal_cost_centers_tenant_status_index',
            'cost_center_allocations_tenant_booking_index',
            'cost_center_allocations_booking_index',
            'cost_center_allocations_tenant_center_index',
            'cost_center_allocations_center_index',
        ])
        ->orderBy('indexname')
        ->pluck('indexname')
        ->all();

    expect($constraints)->toHaveCount(5)
        ->and($indexes)->toHaveCount(11);
});

test('rejects cross-tenant contract customer associations', function (): void {
    $customer = Customer::factory()->create();
    $otherTenant = TenantKey::factory()->create();

    DB::table('contracts')->insert(contractPersistenceRow($customer, [
        'tenant_id' => $otherTenant->id,
    ]));
})->throws(QueryException::class);

test('retains authoritative customer traversal after customer retirement', function (): void {
    $contract = Contract::factory()->create();
    $customer = $contract->customer;

    $customer->delete();

    expect($contract->refresh()->customer)->not->toBeNull()
        ->and($contract->customer->is($customer))->toBeTrue()
        ->and($contract->customer->trashed())->toBeTrue();
});

test('enforces contract type date lifecycle price and currency invariants', function (array $overrides): void {
    $customer = Customer::factory()->create();
    DB::table('contracts')->insert(contractPersistenceRow($customer, $overrides));
})->with([
    'unknown type' => [['type' => 'subscription']],
    'unknown status' => [['status' => 'draft']],
    'end before start' => [['ends_on' => '2026-08-31']],
    'active with retirement time' => [['retired_at' => now()]],
    'retired without retirement time' => [['status' => 'retired']],
    'negative unit price' => [['unit_price' => '-0.0001']],
    'non-finite unit price' => [['unit_price' => 'NaN']],
    'lowercase currency' => [['currency_code' => 'eur']],
    'non-ASCII currency' => [['currency_code' => 'EU1']],
])->throws(QueryException::class);

test('prevents customer and currency reassignment after booking history exists', function (string $column, mixed $value): void {
    $contract = Contract::factory()->create();
    ServiceBooking::factory()->create([
        'tenant_id' => $contract->tenant_id,
        'contract_id' => $contract->id,
    ]);

    if ($column === 'customer_id') {
        $value = Customer::factory()->create(['tenant_id' => $contract->tenant_id])->id;
    }

    DB::table('contracts')->where('id', $contract->id)->update([$column => $value]);
})->with([
    'customer' => ['customer_id', null],
    'currency' => ['currency_code', 'USD'],
])->throws(QueryException::class);

test('allows customer and currency reassignment before booking history exists', function (): void {
    $contract = Contract::factory()->create();
    $customer = Customer::factory()->create(['tenant_id' => $contract->tenant_id]);

    $contract->update([
        'customer_id' => $customer->id,
        'currency_code' => 'USD',
    ]);

    expect($contract->fresh()?->customer_id)->toBe($customer->id)
        ->and($contract->fresh()?->currency_code)->toBe('USD');
});

test('rejects cross-tenant booking contract associations', function (): void {
    $contract = Contract::factory()->create();
    $otherTenant = TenantKey::factory()->create();

    DB::table('service_bookings')->insert(serviceBookingPersistenceRow($contract, [
        'tenant_id' => $otherTenant->id,
    ]));
})->throws(QueryException::class);

test('enforces booking quantity price vocabulary currency and lifecycle invariants', function (array $overrides): void {
    $contract = Contract::factory()->create();
    DB::table('service_bookings')->insert(serviceBookingPersistenceRow($contract, $overrides));
})->with([
    'zero quantity' => [['quantity' => '0.0000']],
    'negative quantity' => [['quantity' => '-1.0000']],
    'non-finite quantity' => [['quantity' => 'NaN']],
    'negative unit price' => [['unit_price' => '-0.0001']],
    'non-finite unit price' => [['unit_price' => 'NaN']],
    'unknown billing unit' => [['billing_unit' => 'shift']],
    'different currency' => [['currency_code' => 'USD']],
    'unbilled with invoice time' => [['invoiced_at' => now()]],
    'invoiced without invoice time' => [['invoice_state' => 'invoiced']],
    'active with retirement time' => [['retired_at' => now()]],
    'retired without retirement time' => [['status' => 'retired']],
])->throws(QueryException::class);

test('derives and deterministically rounds the authoritative booking total', function (string $quantity, string $price, string $expected): void {
    $contract = Contract::factory()->create();
    $id = Str::uuid()->toString();
    DB::table('service_bookings')->insert(serviceBookingPersistenceRow($contract, [
        'id' => $id,
        'quantity' => $quantity,
        'unit_price' => $price,
    ]));

    expect((string) DB::table('service_bookings')->where('id', $id)->value('total'))->toBe($expected);
})->with([
    'rounds down below half cent' => ['1.0000', '10.0049', '10.00'],
    'rounds up at half cent' => ['1.0000', '10.0050', '10.01'],
    'multiplies exact decimals before rounding' => ['3.3333', '19.9999', '66.67'],
]);

test('does not accept a caller-owned booking total', function (): void {
    $contract = Contract::factory()->create();
    DB::table('service_bookings')->insert(serviceBookingPersistenceRow($contract, [
        'total' => '0.01',
    ]));
})->throws(QueryException::class);

test('makes invoiced financial facts and invoice evidence immutable', function (array $changes): void {
    $contract = Contract::factory()->create(['currency_code' => 'EUR']);
    $booking = ServiceBooking::factory()->forContract($contract)->invoiced()->create([
        'service_date' => '2026-09-05',
        'quantity' => '2.0000',
        'billing_unit' => BillingUnit::Hour,
        'unit_price' => '42.0000',
    ]);
    DB::table('service_bookings')->where('id', $booking->id)->update($changes);
})->with([
    'contract' => [['contract_id' => Str::uuid()->toString()]],
    'service date' => [['service_date' => '2026-09-06']],
    'quantity' => [['quantity' => '3.0000']],
    'billing unit' => [['billing_unit' => 'day']],
    'unit price' => [['unit_price' => '43.0000']],
    'currency' => [['currency_code' => 'USD']],
    'invoice state reversal' => [['invoice_state' => 'unbilled', 'invoiced_at' => null]],
    'invoice timestamp rewrite' => [['invoiced_at' => now()->addMinute()]],
])->throws(QueryException::class);

test('allows consistent retirement metadata on invoiced booking evidence', function (): void {
    $booking = ServiceBooking::factory()->invoiced()->create();
    $booking->update([
        'status' => ServiceBookingStatus::Retired,
        'retired_at' => now(),
    ]);

    expect($booking->fresh()?->status)->toBe(ServiceBookingStatus::Retired)
        ->and($booking->fresh()?->retired_at)->not->toBeNull();
});

test('prevents direct deletion of invoiced booking evidence', function (): void {
    $booking = ServiceBooking::factory()->invoiced()->create();

    DB::table('service_bookings')->where('id', $booking->id)->delete();
})->throws(QueryException::class);

test('scopes cost center codes to tenants and retains inactive centers', function (): void {
    $center = InternalCostCenter::factory()->create(['code' => 'OPS-42']);
    $otherTenant = TenantKey::factory()->create();

    InternalCostCenter::factory()->create([
        'tenant_id' => $otherTenant->id,
        'code' => 'OPS-42',
    ]);
    $center->update([
        'status' => InternalCostCenterStatus::Inactive,
        'inactive_at' => now(),
    ]);

    expect($center->fresh())->not->toBeNull()
        ->and(InternalCostCenter::query()->where('code', 'OPS-42')->count())->toBe(2);

    InternalCostCenter::factory()->create([
        'tenant_id' => $center->tenant_id,
        'code' => 'OPS-42',
    ]);
})->throws(QueryException::class);

test('rejects blank codes and contradictory cost center lifecycle data', function (array $overrides): void {
    InternalCostCenter::factory()->create($overrides);
})->with([
    'blank code' => [['code' => '   ']],
    'tab-only code' => [['code' => "\t\t"]],
    'active with timestamp' => [['inactive_at' => now()]],
    'inactive without timestamp' => [['status' => 'inactive']],
])->throws(QueryException::class);

test('preserves nonblank internal cost center codes exactly as supplied', function (): void {
    $center = InternalCostCenter::factory()->create(['code' => ' OPS-42 ']);

    expect($center->fresh()?->code)->toBe(' OPS-42 ');
});

test('refuses an unsafe code-contract rollback before changing its constraint', function (): void {
    $center = InternalCostCenter::factory()->create(['code' => ' OPS-42 ']);
    $migration = require database_path('migrations/2026_09_10_230000_align_internal_cost_center_code_contract.php');

    expect(fn () => $migration->down())->toThrow(
        RuntimeException::class,
        'Cannot rollback the Internal Cost Center code contract while preserved codes contain boundary whitespace.',
    );

    expect($center->fresh()?->code)->toBe(' OPS-42 ');
});

test('keeps internal cost center codes stable while allowing display name changes', function (): void {
    $center = InternalCostCenter::factory()->create(['code' => 'STABLE-42']);
    $center->update(['name' => 'Updated display name']);

    expect($center->fresh()?->name)->toBe('Updated display name');

    $center->update(['code' => 'REASSIGNED-42']);
})->throws(QueryException::class);

test('rejects new allocations to inactive cost centers', function (): void {
    $booking = ServiceBooking::factory()->create();
    $center = InternalCostCenter::factory()->inactive()->create([
        'tenant_id' => $booking->tenant_id,
    ]);

    CostCenterAllocation::factory()->create([
        'tenant_id' => $booking->tenant_id,
        'service_booking_id' => $booking->id,
        'internal_cost_center_id' => $center->id,
    ]);
})->throws(QueryException::class);

test('retains historical allocations after a cost center becomes inactive', function (): void {
    $allocation = CostCenterAllocation::factory()->create();
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    $center = $allocation->internalCostCenter;

    $center->update([
        'status' => InternalCostCenterStatus::Inactive,
        'inactive_at' => now(),
    ]);

    expect($allocation->fresh())->not->toBeNull()
        ->and($allocation->fresh()?->internalCostCenter->status)->toBe(InternalCostCenterStatus::Inactive);
});

test('rejects allocation reassignment to an inactive cost center', function (): void {
    $allocation = CostCenterAllocation::factory()->create();
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    $inactiveCenter = InternalCostCenter::factory()->inactive()->create([
        'tenant_id' => $allocation->tenant_id,
    ]);

    $allocation->update(['internal_cost_center_id' => $inactiveCenter->id]);
})->throws(QueryException::class);

test('rejects moving an existing inactive-center allocation to another booking', function (): void {
    $booking = ServiceBooking::factory()->create();
    $otherBooking = ServiceBooking::factory()->forContract($booking->contract)->create();
    $center = InternalCostCenter::factory()->create(['tenant_id' => $booking->tenant_id]);
    $allocation = CostCenterAllocation::factory()->create([
        'tenant_id' => $booking->tenant_id,
        'service_booking_id' => $booking->id,
        'internal_cost_center_id' => $center->id,
    ]);
    $center->update([
        'status' => InternalCostCenterStatus::Inactive,
        'inactive_at' => now(),
    ]);

    DB::table('cost_center_allocations')->where('id', $allocation->id)->update([
        'service_booking_id' => $otherBooking->id,
    ]);
})->throws(QueryException::class);

test('enforces allocation basis-point bounds', function (int $share): void {
    $booking = ServiceBooking::factory()->create();

    CostCenterAllocation::factory()->create([
        'tenant_id' => $booking->tenant_id,
        'service_booking_id' => $booking->id,
        'internal_cost_center_id' => InternalCostCenter::factory()->create([
            'tenant_id' => $booking->tenant_id,
        ])->id,
        'share_bps' => $share,
    ]);
})->with([0, 10001])->throws(QueryException::class);

test('rejects cross-tenant and duplicate allocation identities', function (string $case): void {
    $booking = ServiceBooking::factory()->create();
    $otherTenant = TenantKey::factory()->create();
    $sameTenantCenter = InternalCostCenter::factory()->create(['tenant_id' => $booking->tenant_id]);
    $otherTenantCenter = InternalCostCenter::factory()->create(['tenant_id' => $otherTenant->id]);

    if ($case === 'booking') {
        CostCenterAllocation::factory()->create([
            'tenant_id' => $otherTenant->id,
            'service_booking_id' => $booking->id,
            'internal_cost_center_id' => $otherTenantCenter->id,
        ]);
    } elseif ($case === 'center') {
        CostCenterAllocation::factory()->create([
            'tenant_id' => $booking->tenant_id,
            'service_booking_id' => $booking->id,
            'internal_cost_center_id' => $otherTenantCenter->id,
        ]);
    } else {
        CostCenterAllocation::factory()->create([
            'tenant_id' => $booking->tenant_id,
            'service_booking_id' => $booking->id,
            'internal_cost_center_id' => $sameTenantCenter->id,
        ]);
        CostCenterAllocation::factory()->create([
            'tenant_id' => $booking->tenant_id,
            'service_booking_id' => $booking->id,
            'internal_cost_center_id' => $sameTenantCenter->id,
        ]);
    }
})->with(['booking', 'center', 'duplicate'])->throws(QueryException::class);

test('accepts optional and complete allocation splits', function (array $shares): void {
    $booking = ServiceBooking::factory()->create();

    DB::transaction(function () use ($booking, $shares): void {
        foreach ($shares as $share) {
            CostCenterAllocation::factory()->create([
                'tenant_id' => $booking->tenant_id,
                'service_booking_id' => $booking->id,
                'internal_cost_center_id' => InternalCostCenter::factory()->create([
                    'tenant_id' => $booking->tenant_id,
                ])->id,
                'share_bps' => $share,
            ]);
        }

        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    });

    expect($booking->costCenterAllocations()->sum('share_bps'))->toBe(array_sum($shares));
})->with([
    'none' => [[]],
    'one center' => [[10000]],
    'equal split' => [[5000, 5000]],
    'thirds' => [[3333, 3333, 3334]],
]);

test('rejects incomplete and over-complete allocation splits at constraint evaluation', function (array $shares): void {
    $booking = ServiceBooking::factory()->create();

    DB::transaction(function () use ($booking, $shares): void {
        foreach ($shares as $share) {
            CostCenterAllocation::factory()->create([
                'tenant_id' => $booking->tenant_id,
                'service_booking_id' => $booking->id,
                'internal_cost_center_id' => InternalCostCenter::factory()->create([
                    'tenant_id' => $booking->tenant_id,
                ])->id,
                'share_bps' => $share,
            ]);
        }

        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    });
})->with([
    'one half' => [[5000]],
    'one basis point short' => [[5000, 4999]],
    'over complete' => [[6000, 5000]],
])->throws(QueryException::class);

test('transactionally replaces a split and removes every allocation', function (): void {
    $booking = ServiceBooking::factory()->create();
    CostCenterAllocation::factory()->createCompleteSplit($booking, [6000, 4000]);
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split DEFERRED');

    DB::transaction(function () use ($booking): void {
        $booking->costCenterAllocations()->delete();
        CostCenterAllocation::factory()->createCompleteSplit($booking, [5000, 3000, 2000]);
        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    });

    expect($booking->costCenterAllocations()->sum('share_bps'))->toBe(10000)
        ->and($booking->costCenterAllocations()->count())->toBe(3);

    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split DEFERRED');
    DB::transaction(function () use ($booking): void {
        $booking->costCenterAllocations()->delete();
        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    });

    expect($booking->costCenterAllocations()->count())->toBe(0);
});

test('models factories casts scopes and relationships represent tenant-consistent graphs', function (): void {
    $contract = Contract::factory()->retired()->create();
    $booking = ServiceBooking::factory()->invoiced()->retired()->create([
        'tenant_id' => $contract->tenant_id,
        'contract_id' => $contract->id,
        'currency_code' => $contract->currency_code,
    ]);
    $allocations = CostCenterAllocation::factory()->createCompleteSplit($booking, [5000, 5000]);
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
    $center = $allocations->firstOrFail()->internalCostCenter;

    expect($contract->getKeyType())->toBe('string')
        ->and($contract->getIncrementing())->toBeFalse()
        ->and($booking->getKeyType())->toBe('string')
        ->and($center->getKeyType())->toBe('string')
        ->and($allocations->firstOrFail()->getKeyType())->toBe('string')
        ->and($contract->type)->toBeInstanceOf(ContractType::class)
        ->and($contract->status)->toBe(ContractStatus::Retired)
        ->and($contract->billing_unit)->toBeInstanceOf(BillingUnit::class)
        ->and($contract->customer->tenant_id)->toBe($contract->tenant_id)
        ->and($contract->customer->contracts->contains($contract))->toBeTrue()
        ->and($contract->serviceBookings->contains($booking))->toBeTrue()
        ->and($booking->invoice_state)->toBe(InvoiceState::Invoiced)
        ->and($booking->status)->toBe(ServiceBookingStatus::Retired)
        ->and($booking->contract->tenant_id)->toBe($booking->tenant_id)
        ->and($booking->costCenterAllocations)->toHaveCount(2)
        ->and($center->status)->toBe(InternalCostCenterStatus::Active)
        ->and($center->costCenterAllocations->first()?->serviceBooking->is($booking))->toBeTrue()
        ->and(Contract::query()->forTenant($contract->tenant_id)->retired()->count())->toBe(1)
        ->and(ServiceBooking::query()->forTenant($booking->tenant_id)->invoiced()->retired()->count())->toBe(1)
        ->and(InternalCostCenter::query()->forTenant($center->tenant_id)->active()->count())->toBeGreaterThanOrEqual(1);
});

test('default factories create a complete tenant-consistent allocation graph', function (): void {
    $allocation = CostCenterAllocation::factory()->create();
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');

    expect($allocation->share_bps)->toBe(10000)
        ->and($allocation->tenant_id)->toBe($allocation->serviceBooking->tenant_id)
        ->and($allocation->tenant_id)->toBe($allocation->serviceBooking->contract->tenant_id)
        ->and($allocation->tenant_id)->toBe($allocation->serviceBooking->contract->customer->tenant_id)
        ->and($allocation->tenant_id)->toBe($allocation->internalCostCenter->tenant_id);
});

test('aggregate relationships support bounded eager loading without hidden per-row queries', function (): void {
    $tenant = TenantKey::factory()->create();

    foreach (range(1, 2) as $index) {
        $contract = Contract::factory()->create(['tenant_id' => $tenant->id]);
        $booking = ServiceBooking::factory()->create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'currency_code' => $contract->currency_code,
        ]);
        CostCenterAllocation::factory()->create([
            'tenant_id' => $tenant->id,
            'service_booking_id' => $booking->id,
            'internal_cost_center_id' => InternalCostCenter::factory()->create([
                'tenant_id' => $tenant->id,
                'code' => "LOAD-{$index}",
            ])->id,
        ]);
    }
    DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $contracts = Contract::query()
        ->forTenant($tenant->id)
        ->with(['customer', 'serviceBookings.costCenterAllocations.internalCostCenter'])
        ->get();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($contracts)->toHaveCount(2)
        ->and($queryCount)->toBe(5);
});

test('tenant erasure removes the complete contract management graph in dependency order', function (): void {
    $allocation = CostCenterAllocation::factory()->create();
    $tenant = $allocation->tenant;
    $contractId = $allocation->serviceBooking->contract_id;
    $bookingId = $allocation->service_booking_id;
    $centerId = $allocation->internal_cost_center_id;
    $allocationId = $allocation->id;

    $tenant->delete();

    expect(Contract::query()->whereKey($contractId)->exists())->toBeFalse()
        ->and(ServiceBooking::query()->whereKey($bookingId)->exists())->toBeFalse()
        ->and(InternalCostCenter::query()->whereKey($centerId)->exists())->toBeFalse()
        ->and(CostCenterAllocation::query()->whereKey($allocationId)->exists())->toBeFalse();
});

test('the migration rolls back and reapplies without orphaned trigger functions', function (): void {
    $migration = require database_path('migrations/2026_09_10_120000_create_contract_management_persistence.php');
    $migration->down();

    $functions = DB::table('pg_proc')
        ->whereRaw('pronamespace = current_schema()::regnamespace')
        ->whereIn('proname', [
            'enforce_contract_history',
            'enforce_service_booking_history',
            'enforce_internal_cost_center_identity',
            'lock_cost_center_allocation_owners',
            'enforce_active_cost_center_allocation',
            'enforce_complete_cost_center_allocation',
        ])
        ->pluck('proname')
        ->all();

    expect(Schema::hasTable('contracts'))->toBeFalse()
        ->and(Schema::hasTable('service_bookings'))->toBeFalse()
        ->and(Schema::hasTable('internal_cost_centers'))->toBeFalse()
        ->and(Schema::hasTable('cost_center_allocations'))->toBeFalse()
        ->and($functions)->toBe([]);

    $migration->up();

    expect(Schema::hasTable('contracts'))->toBeTrue()
        ->and(Schema::hasTable('service_bookings'))->toBeTrue()
        ->and(Schema::hasTable('internal_cost_centers'))->toBeTrue()
        ->and(Schema::hasTable('cost_center_allocations'))->toBeTrue();
});
