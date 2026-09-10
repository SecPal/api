<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Contract;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\ServiceBookingAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->actor->createToken('service-booking-api')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantServiceBookingApiPermissions(object $test, string ...$abilities): void
{
    foreach ($abilities as $ability) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $ability);
    }
}

/** @return array<string, mixed> */
function validServiceBookingPayload(Contract $contract, array $overrides = []): array
{
    return array_merge([
        'contract_id' => $contract->id,
        'service_date' => '2026-10-01',
        'quantity' => '8.5000',
        'billing_unit' => 'day',
        'unit_price' => '42.5000',
    ], $overrides);
}

test('Service Booking routes require authentication and their exact capability', function (): void {
    $this->getJson('/v1/service-bookings')->assertUnauthorized();

    foreach ([
        ['GET', '/v1/service-bookings'],
        ['POST', '/v1/service-bookings'],
        ['GET', '/v1/service-bookings/11111111-1111-4111-8111-111111111111'],
        ['PATCH', '/v1/service-bookings/11111111-1111-4111-8111-111111111111'],
        ['POST', '/v1/service-bookings/11111111-1111-4111-8111-111111111111/retire'],
    ] as [$method, $uri]) {
        $this->withToken($this->token)->json($method, $uri)->assertForbidden();
    }
});

test('list is tenant isolated, includes both states, and uses deterministic pagination', function (): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.read');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    ServiceBooking::factory()->create(['tenant_id' => $foreignTenant->id]);
    $createdAt = now()->subHour()->startOfSecond();
    ServiceBooking::factory()->count(16)->sequence(
        fn ($sequence): array => [
            'tenant_id' => $this->tenant->id,
            'created_at' => $createdAt->copy()->subMinutes($sequence->index),
        ],
    )->create();
    ServiceBooking::factory()->retired()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => $createdAt,
    ]);

    $expected = ServiceBooking::query()->forTenant($this->tenant->id)
        ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->pluck('id')->all();
    $response = $this->withToken($this->token)->getJson('/v1/service-bookings');

    $response->assertOk()->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(collect($response->json('data'))->pluck('status'))->toContain('retired');
});

test('list validates pagination and rejects unsupported query fields', function (string $query): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.read');

    $this->withToken($this->token)->getJson('/v1/service-bookings?'.$query)->assertUnprocessable();
})->with([
    'page=0', 'page=nope', 'per_page=0', 'per_page=101', 'per_page=nope',
    'status=active', 'contract_id=11111111-1111-4111-8111-111111111111', 'sort=id', 'tenant_id=999',
]);

test('create derives tenant, Contract currency, DB total, lifecycle, and one bounded audit', function (): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.create');
    $contract = Contract::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_unit' => 'hour',
        'unit_price' => '99.0000',
        'currency_code' => 'EUR',
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/v1/service-bookings', validServiceBookingPayload($contract));

    $response->assertCreated()
        ->assertJsonPath('data.contract_id', $contract->id)
        ->assertJsonPath('data.service_date', '2026-10-01')
        ->assertJsonPath('data.quantity', '8.5000')
        ->assertJsonPath('data.billing_unit', 'day')
        ->assertJsonPath('data.unit_price', '42.5000')
        ->assertJsonPath('data.currency_code', 'EUR')
        ->assertJsonPath('data.total', '361.25')
        ->assertJsonPath('data.invoice_state', 'unbilled')
        ->assertJsonPath('data.invoiced_at', null)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.retired_at', null)
        ->assertJsonMissingPath('data.tenant_id');

    expect(array_keys($response->json('data')))->toBe([
        'id', 'contract_id', 'service_date', 'quantity', 'billing_unit', 'unit_price',
        'currency_code', 'total', 'invoice_state', 'invoiced_at', 'status', 'retired_at',
        'created_at', 'updated_at',
    ])->and($response->json('data.quantity'))->toBeString()
        ->and($response->json('data.unit_price'))->toBeString()
        ->and($response->json('data.total'))->toBeString()
        ->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $booking = ServiceBooking::query()->sole();
    expect($booking->tenant_id)->toBe($this->tenant->id)
        ->and($booking->currency_code)->toBe('EUR')
        ->and($booking->total)->toBe('361.25');

    $audit = Activity::query()->where('event', 'service_booking.create')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->causer_id)->toBe($this->actor->id)
        ->and($audit->subject_id)->toBe($booking->id)
        ->and($audit->properties->keys()->all())->toBe([
            'schema_version', 'operation', 'service_booking_id', 'changed_fields', 'changes',
        ])
        ->and(data_get($audit->properties->all(), 'changes.total.current'))->toBe('361.25')
        ->and($audit->properties->has('request'))->toBeFalse();
});

test('create does not require contracts.read and accepts Contract snapshot differences', function (): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.create');
    $contract = Contract::factory()->retired()->create([
        'tenant_id' => $this->tenant->id,
        'billing_unit' => 'flat',
        'unit_price' => '999.0000',
        'currency_code' => 'USD',
    ]);

    $this->withToken($this->token)->postJson('/v1/service-bookings', validServiceBookingPayload($contract, [
        'billing_unit' => 'unit',
        'unit_price' => '10.0000',
    ]))->assertCreated()
        ->assertJsonPath('data.billing_unit', 'unit')
        ->assertJsonPath('data.unit_price', '10.0000')
        ->assertJsonPath('data.currency_code', 'USD');
});

test('create conceals unavailable Contracts and rejects malformed Contract UUIDs', function (string $kind): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.create');
    $contract = Contract::factory()->make(['id' => (string) Str::uuid()]);

    if ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $contract = Contract::factory()->create(['tenant_id' => $foreignTenant->id]);
    } elseif ($kind === 'malformed') {
        $contract->id = 'not-a-uuid';
    }

    $response = $this->withToken($this->token)
        ->postJson('/v1/service-bookings', validServiceBookingPayload($contract));

    if ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('contract_id');
    } else {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
    expect(ServiceBooking::query()->count())->toBe(0);
})->with(['missing', 'foreign', 'malformed']);

test('create enforces the exact closed request contract', function (string $case, string $field): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.create');
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id]);
    $payload = match ($case) {
        'date' => validServiceBookingPayload($contract, ['service_date' => '2026-02-30']),
        'numeric quantity' => validServiceBookingPayload($contract, ['quantity' => 8.5]),
        'zero quantity' => validServiceBookingPayload($contract, ['quantity' => '0.0000']),
        'negative quantity' => validServiceBookingPayload($contract, ['quantity' => '-1']),
        'exponent quantity' => validServiceBookingPayload($contract, ['quantity' => '1e3']),
        'precise quantity' => validServiceBookingPayload($contract, ['quantity' => '1.00000']),
        'large quantity' => validServiceBookingPayload($contract, ['quantity' => '10000000000.0000']),
        'billing unit' => validServiceBookingPayload($contract, ['billing_unit' => 'minute']),
        'numeric price' => validServiceBookingPayload($contract, ['unit_price' => 42.5]),
        'negative price' => validServiceBookingPayload($contract, ['unit_price' => '-1']),
        'exponent price' => validServiceBookingPayload($contract, ['unit_price' => '1e3']),
        'precise price' => validServiceBookingPayload($contract, ['unit_price' => '1.00000']),
        'large price' => validServiceBookingPayload($contract, ['unit_price' => '10000000000.0000']),
        'unknown' => validServiceBookingPayload($contract, ['shift_id' => (string) Str::uuid()]),
        'tenant' => validServiceBookingPayload($contract, ['tenant_id' => 999]),
        'currency' => validServiceBookingPayload($contract, ['currency_code' => 'USD']),
        'total' => validServiceBookingPayload($contract, ['total' => '1.00']),
        'invoice state' => validServiceBookingPayload($contract, ['invoice_state' => 'invoiced']),
        'invoiced at' => validServiceBookingPayload($contract, ['invoiced_at' => now()->toIso8601String()]),
        'status' => validServiceBookingPayload($contract, ['status' => 'retired']),
        'retired at' => validServiceBookingPayload($contract, ['retired_at' => now()->toIso8601String()]),
        'id' => validServiceBookingPayload($contract, ['id' => (string) Str::uuid()]),
        'created at' => validServiceBookingPayload($contract, ['created_at' => now()->toIso8601String()]),
    };

    $this->withToken($this->token)->postJson('/v1/service-bookings', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['date', 'service_date'], ['numeric quantity', 'quantity'], ['zero quantity', 'quantity'],
    ['negative quantity', 'quantity'], ['exponent quantity', 'quantity'], ['precise quantity', 'quantity'],
    ['large quantity', 'quantity'], ['billing unit', 'billing_unit'], ['numeric price', 'unit_price'],
    ['negative price', 'unit_price'], ['exponent price', 'unit_price'], ['precise price', 'unit_price'],
    ['large price', 'unit_price'], ['unknown', 'shift_id'], ['tenant', 'tenant_id'],
    ['currency', 'currency_code'], ['total', 'total'], ['invoice state', 'invoice_state'],
    ['invoiced at', 'invoiced_at'], ['status', 'status'], ['retired at', 'retired_at'],
    ['id', 'id'], ['created at', 'created_at'],
]);

test('exact decimals are accepted without float conversion', function (string $quantity, string $price, string $total): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.create');
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)->postJson('/v1/service-bookings', validServiceBookingPayload($contract, [
        'quantity' => $quantity,
        'unit_price' => $price,
    ]))->assertCreated()
        ->assertJsonPath('data.quantity', str_contains($quantity, '.') ? str_pad($quantity, strlen(explode('.', $quantity)[0]) + 5, '0') : $quantity.'.0000')
        ->assertJsonPath('data.total', $total);
})->with([
    ['1', '0', '0.00'],
    ['0.5', '0.0001', '0.00'],
    ['8.5000', '42.5000', '361.25'],
    ['9999999999.9999', '0', '0.00'],
]);

test('inspect is tenant safe and distinguishes malformed UUID input', function (string $kind): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.read');
    $id = 'not-a-uuid';
    if ($kind === 'local') {
        $id = ServiceBooking::factory()->create(['tenant_id' => $this->tenant->id])->id;
    } elseif ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = ServiceBooking::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    } elseif ($kind === 'missing') {
        $id = (string) Str::uuid();
    }

    $response = $this->withToken($this->token)->getJson('/v1/service-bookings/'.$id);
    if ($kind === 'local') {
        $response->assertOk()->assertJsonPath('data.id', $id);
    } elseif ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('serviceBooking');
    } else {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
})->with(['local', 'foreign', 'missing', 'malformed']);

test('mutation routes reject malformed Booking UUIDs before lookup', function (string $operation): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.update', 'service_bookings.retire');

    $response = $operation === 'update'
        ? $this->withToken($this->token)->patchJson('/v1/service-bookings/not-a-uuid', ['quantity' => '2'])
        : $this->withToken($this->token)->postJson('/v1/service-bookings/not-a-uuid/retire');

    $response->assertUnprocessable()->assertJsonValidationErrors('serviceBooking');
})->with(['update', 'retire']);

test('PATCH is genuinely partial, refreshes the DB total, and audits committed changes once', function (): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.update');
    $booking = ServiceBooking::factory()->create([
        'tenant_id' => $this->tenant->id,
        'service_date' => '2026-10-01',
        'quantity' => '2.0000',
        'billing_unit' => 'hour',
        'unit_price' => '10.0000',
    ]);

    $response = $this->withToken($this->token)->patchJson('/v1/service-bookings/'.$booking->id, [
        'quantity' => '3.5000',
        'unit_price' => '10.0750',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.service_date', '2026-10-01')
        ->assertJsonPath('data.billing_unit', 'hour')
        ->assertJsonPath('data.quantity', '3.5000')
        ->assertJsonPath('data.unit_price', '10.0750')
        ->assertJsonPath('data.total', '35.26');
    $audit = Activity::query()->where('event', 'service_booking.update')->sole();
    expect($audit->properties->get('changed_fields'))->toBe(['quantity', 'unit_price'])
        ->and(data_get($audit->properties->all(), 'changes.quantity.previous'))->toBe('2.0000')
        ->and(data_get($audit->properties->all(), 'changes.quantity.current'))->toBe('3.5000')
        ->and($audit->properties->keys()->all())->toBe([
            'schema_version', 'operation', 'service_booking_id', 'changed_fields', 'changes',
        ]);
});

test('PATCH rejects empty, immutable, server-owned, and unknown fields', function (array $payload, string $field): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.update');
    $booking = ServiceBooking::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)->patchJson('/v1/service-bookings/'.$booking->id, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'empty' => [[], 'serviceBooking'],
    'contract' => [['contract_id' => '11111111-1111-4111-8111-111111111111'], 'contract_id'],
    'currency' => [['currency_code' => 'USD'], 'currency_code'],
    'total' => [['total' => '1.00'], 'total'],
    'invoice' => [['invoice_state' => 'invoiced'], 'invoice_state'],
    'invoice timestamp' => [['invoiced_at' => '2026-10-01T00:00:00Z'], 'invoiced_at'],
    'status' => [['status' => 'retired'], 'status'],
    'retirement timestamp' => [['retired_at' => '2026-10-01T00:00:00Z'], 'retired_at'],
    'tenant' => [['tenant_id' => 1], 'tenant_id'],
    'unknown' => [['shift_id' => '11111111-1111-4111-8111-111111111111'], 'shift_id'],
]);

test('PATCH returns closed state conflicts without mutation or false audit', function (string $state): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.update');
    $factory = ServiceBooking::factory()->forTenant($this->tenant->id);
    $booking = $state === 'retired' ? $factory->retired()->create() : $factory->invoiced()->create();
    $original = $booking->refresh()->getRawOriginal();

    $message = $state === 'retired'
        ? 'The Service Booking is retired.'
        : 'The Service Booking is invoiced.';
    $this->withToken($this->token)->patchJson('/v1/service-bookings/'.$booking->id, [
        'quantity' => '2.0000',
    ])->assertConflict()->assertExactJson(['message' => $message, 'code' => 'CONFLICT']);

    expect($booking->fresh()?->getRawOriginal())->toBe($original)
        ->and(Activity::query()->where('event', 'service_booking.update')->count())->toBe(0);
})->with(['retired', 'invoiced']);

test('mutations conceal foreign and nonexistent valid Booking IDs', function (string $operation, string $kind): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.update', 'service_bookings.retire');
    $id = (string) Str::uuid();
    if ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = ServiceBooking::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    }

    $response = $operation === 'update'
        ? $this->withToken($this->token)->patchJson('/v1/service-bookings/'.$id, ['quantity' => '2'])
        : $this->withToken($this->token)->postJson('/v1/service-bookings/'.$id.'/retire');
    $response->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
})->with([
    ['update', 'foreign'], ['update', 'missing'], ['retire', 'foreign'], ['retire', 'missing'],
]);

test('retirement is terminal and preserves both unbilled and invoiced financial evidence', function (string $invoiceState): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.retire');
    $factory = ServiceBooking::factory()->forTenant($this->tenant->id);
    $booking = $invoiceState === 'invoiced' ? $factory->invoiced()->create() : $factory->create();
    $this->travelTo(now()->startOfSecond());
    $financialFields = [
        'contract_id', 'service_date', 'quantity', 'billing_unit', 'unit_price',
        'currency_code', 'total', 'invoice_state', 'invoiced_at',
    ];
    $financial = array_intersect_key($booking->refresh()->getRawOriginal(), array_flip($financialFields));

    $this->withToken($this->token)->postJson('/v1/service-bookings/'.$booking->id.'/retire')
        ->assertOk()->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.retired_at', now()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->assertJsonPath('data.invoice_state', $invoiceState);

    expect(array_intersect_key($booking->fresh()?->getRawOriginal() ?? [], array_flip($financialFields)))
        ->toBe($financial)
        ->and(Activity::query()->where('event', 'service_booking.retire')->count())->toBe(1);

    $this->withToken($this->token)->postJson('/v1/service-bookings/'.$booking->id.'/retire')
        ->assertConflict()
        ->assertExactJson(['message' => 'The Service Booking is retired.', 'code' => 'CONFLICT']);
    expect(Activity::query()->where('event', 'service_booking.retire')->count())->toBe(1);
})->with(['unbilled', 'invoiced']);

test('mutations reject query input and retire rejects every body field', function (): void {
    grantServiceBookingApiPermissions(
        $this,
        'service_bookings.create',
        'service_bookings.update',
        'service_bookings.retire',
    );
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id]);
    $booking = ServiceBooking::factory()->forContract($contract)->create();

    $this->withToken($this->token)->postJson('/v1/service-bookings?quantity=1', validServiceBookingPayload($contract))
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->withToken($this->token)->patchJson('/v1/service-bookings/'.$booking->id.'?quantity=2', [
        'service_date' => '2026-10-02',
    ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->withToken($this->token)->postJson('/v1/service-bookings/'.$booking->id.'/retire?reason=nope')
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->withToken($this->token)->postJson('/v1/service-bookings/'.$booking->id.'/retire', ['reason' => 'nope'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
});

test('required audit failure rolls back create without false success evidence', function (): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.create');
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->mock(ServiceBookingAuditRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('recordCreate')->once()->andThrow(new RuntimeException('audit unavailable'));
    });

    $this->withToken($this->token)->postJson('/v1/service-bookings', validServiceBookingPayload($contract))
        ->assertInternalServerError()
        ->assertJsonMissing(['audit unavailable']);

    expect(ServiceBooking::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'service_booking.create')->count())->toBe(0);
});

test('required audit failure rolls back update and retirement', function (string $operation): void {
    grantServiceBookingApiPermissions($this, 'service_bookings.update', 'service_bookings.retire');
    $booking = ServiceBooking::factory()->create([
        'tenant_id' => $this->tenant->id,
        'quantity' => '2.0000',
        'status' => 'active',
        'retired_at' => null,
    ])->refresh();
    $original = $booking->getRawOriginal();
    $realAudits = new ServiceBookingAuditRecorder;
    $audits = Mockery::mock(ServiceBookingAuditRecorder::class);
    $audits->shouldReceive('snapshot')
        ->once()
        ->andReturnUsing(fn (ServiceBooking $current): array => $realAudits->snapshot($current));
    $audits->shouldReceive($operation === 'update' ? 'recordUpdate' : 'recordRetire')
        ->once()
        ->andThrow(new RuntimeException('audit unavailable'));
    app()->instance(ServiceBookingAuditRecorder::class, $audits);

    $response = $operation === 'update'
        ? $this->withToken($this->token)->patchJson('/v1/service-bookings/'.$booking->id, ['quantity' => '3'])
        : $this->withToken($this->token)->postJson('/v1/service-bookings/'.$booking->id.'/retire');

    $response->assertInternalServerError()->assertJsonMissing(['audit unavailable']);
    expect($booking->fresh()?->getRawOriginal())->toBe($original)
        ->and(Activity::query()->where('event', 'service_booking.'.$operation)->count())->toBe(0);
})->with(['update', 'retire']);
