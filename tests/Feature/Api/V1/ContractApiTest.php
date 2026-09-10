<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Permission;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\ContractAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->actor->createToken('contract-api')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantContractApiPermissions(object $test, string ...$abilities): void
{
    foreach ($abilities as $ability) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $ability);
    }
}

function visibleContractCustomer(object $test): Customer
{
    givePermissionWithTenant($test->actor, $test->tenant->id, 'customers.read');

    return Customer::factory()->create(['tenant_id' => $test->tenant->id]);
}

/** @return array<string, mixed> */
function validContractPayload(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id,
        'type' => 'recurring',
        'starts_on' => '2026-10-01',
        'ends_on' => null,
        'billing_unit' => 'hour',
        'unit_price' => '42.5000',
        'currency_code' => 'EUR',
    ], $overrides);
}

test('the API exposes exactly the five accepted Contract operations', function (): void {
    $operations = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/contracts'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri())
            ->all())
        ->sort()->values()->all();

    expect($operations)->toBe([
        'GET v1/contracts',
        'GET v1/contracts/{contract}',
        'PATCH v1/contracts/{contract}',
        'POST v1/contracts',
        'POST v1/contracts/{contract}/retire',
    ]);
});

test('the permission catalog contains only accepted Contract capabilities and grants none to predefined roles', function (): void {
    expect(Permission::query()->where('name', 'like', 'contracts.%')->orderBy('name')->pluck('name')->all())
        ->toBe(['contracts.create', 'contracts.read', 'contracts.retire', 'contracts.update'])
        ->and(Permission::query()->where('name', 'contracts.delete')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'like', 'contracts:%')->exists())->toBeFalse()
        ->and(Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'like', 'contracts.%'))->exists())
        ->toBeFalse();
});

test('Contract routes require authentication', function (): void {
    $this->getJson('/v1/contracts')->assertUnauthorized();
});

test('each Contract operation requires its exact capability', function (string $method, string $uri): void {
    $this->withToken($this->token)->json($method, $uri)->assertForbidden();
})->with([
    ['GET', '/v1/contracts'],
    ['POST', '/v1/contracts'],
    ['GET', '/v1/contracts/11111111-1111-4111-8111-111111111111'],
    ['PATCH', '/v1/contracts/11111111-1111-4111-8111-111111111111'],
    ['POST', '/v1/contracts/11111111-1111-4111-8111-111111111111/retire'],
]);

test('list is tenant isolated, includes both lifecycle states, and uses deterministic pagination', function (): void {
    grantContractApiPermissions($this, 'contracts.read');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    Contract::factory()->create(['tenant_id' => $foreignTenant->id]);
    $createdAt = now()->subHour()->startOfSecond();
    Contract::factory()->count(16)->sequence(
        fn ($sequence): array => [
            'tenant_id' => $this->tenant->id,
            'created_at' => $createdAt->copy()->subMinutes($sequence->index),
        ],
    )->create();
    Contract::factory()->retired()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => $createdAt,
    ]);

    $expected = Contract::query()->forTenant($this->tenant->id)
        ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->pluck('id')->all();
    $response = $this->withToken($this->token)->getJson('/v1/contracts');

    $response->assertOk()->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(collect($response->json('data'))->pluck('status'))->toContain('retired');
});

test('list validates pagination and rejects unsupported query parameters', function (string $query): void {
    grantContractApiPermissions($this, 'contracts.read');
    $this->withToken($this->token)->getJson('/v1/contracts?'.$query)
        ->assertUnprocessable();
})->with([
    'page=0',
    'page=nope',
    'per_page=0',
    'per_page=101',
    'per_page=nope',
    'status=active',
    'sort=id',
    'tenant_id=999',
]);

test('create derives server state and returns the exact public representation', function (): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customer = visibleContractCustomer($this);
    $response = $this->withToken($this->token)->postJson('/v1/contracts', validContractPayload($customer));

    $response->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.retired_at', null)
        ->assertJsonPath('data.unit_price', '42.5000')
        ->assertJsonMissingPath('data.tenant_id');

    expect(array_keys($response->json('data')))->toBe([
        'id', 'customer_id', 'type', 'status', 'starts_on', 'ends_on',
        'billing_unit', 'unit_price', 'currency_code', 'retired_at',
        'created_at', 'updated_at',
    ])->and($response->json('data.unit_price'))->toBeString()
        ->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $this->assertDatabaseHas('contracts', [
        'id' => $response->json('data.id'),
        'tenant_id' => $this->tenant->id,
        'status' => 'active',
        'retired_at' => null,
    ]);
});

test('create accepts Customer visibility through direct assignment without customers.read', function (): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    CustomerAssignment::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'user_id' => $this->actor->id,
    ]);

    $this->withToken($this->token)->postJson('/v1/contracts', validContractPayload($customer))
        ->assertCreated();
});

test('create conceals unavailable Customers', function (string $kind): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customerId = (string) Str::uuid();

    if ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $customerId = Customer::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    } elseif ($kind === 'inaccessible') {
        $customerId = Customer::factory()->create(['tenant_id' => $this->tenant->id])->id;
    } elseif ($kind === 'soft-deleted') {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $customerId = $customer->id;
        $customer->delete();
    }

    $payload = validContractPayload(Customer::factory()->make(['id' => $customerId]), [
        'customer_id' => $customerId,
    ]);
    $this->withToken($this->token)->postJson('/v1/contracts', $payload)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    expect(Contract::query()->count())->toBe(0);
})->with(['nonexistent', 'foreign', 'inaccessible', 'soft-deleted']);

test('create rejects malformed and closed-contract input', function (string $case, string $field): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customer = visibleContractCustomer($this);
    $payload = match ($case) {
        'customer UUID' => validContractPayload($customer, ['customer_id' => 'nope']),
        'type' => validContractPayload($customer, ['type' => 'fixed']),
        'start date' => validContractPayload($customer, ['starts_on' => '2026-02-30']),
        'date range' => validContractPayload($customer, ['starts_on' => '2026-10-02', 'ends_on' => '2026-10-01']),
        'billing unit' => validContractPayload($customer, ['billing_unit' => 'minute']),
        'numeric price' => validContractPayload($customer, ['unit_price' => 42.5]),
        'negative price' => validContractPayload($customer, ['unit_price' => '-1']),
        'exponent price' => validContractPayload($customer, ['unit_price' => '1e3']),
        'precise price' => validContractPayload($customer, ['unit_price' => '1.00000']),
        'large price' => validContractPayload($customer, ['unit_price' => '10000000000.0000']),
        'leading zero price' => validContractPayload($customer, ['unit_price' => '01.0000']),
        'currency' => validContractPayload($customer, ['currency_code' => 'eur']),
        'unknown' => validContractPayload($customer, ['booking_id' => (string) Str::uuid()]),
        'tenant' => validContractPayload($customer, ['tenant_id' => 999]),
        'status' => validContractPayload($customer, ['status' => 'retired']),
        'retired at' => validContractPayload($customer, ['retired_at' => now()->toIso8601String()]),
    };

    $this->withToken($this->token)->postJson('/v1/contracts', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['customer UUID', 'customer_id'], ['type', 'type'], ['start date', 'starts_on'],
    ['date range', 'ends_on'], ['billing unit', 'billing_unit'], ['numeric price', 'unit_price'],
    ['negative price', 'unit_price'], ['exponent price', 'unit_price'], ['precise price', 'unit_price'],
    ['large price', 'unit_price'], ['leading zero price', 'unit_price'], ['currency', 'currency_code'],
    ['unknown', 'booking_id'], ['tenant', 'tenant_id'], ['status', 'status'], ['retired at', 'retired_at'],
]);

test('unit prices serialize as canonical exact decimal strings', function (string $input, string $expected): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customer = visibleContractCustomer($this);

    $this->withToken($this->token)->postJson('/v1/contracts', validContractPayload($customer, [
        'unit_price' => $input,
    ]))->assertCreated()->assertJsonPath('data.unit_price', $expected);
})->with([
    'zero' => ['0', '0.0000'],
    'short fraction' => ['42.5', '42.5000'],
    'four-place fraction' => ['42.5000', '42.5000'],
    'upper accepted value' => ['9999999999.9999', '9999999999.9999'],
]);

test('Contract mutations reject query-sourced input', function (): void {
    grantContractApiPermissions($this, 'contracts.create', 'contracts.update', 'contracts.retire');
    $customer = visibleContractCustomer($this);
    $queryPayload = http_build_query(validContractPayload($customer));

    $this->withToken($this->token)->postJson('/v1/contracts?'.$queryPayload, [])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    expect(Contract::query()->count())->toBe(0);

    $contract = Contract::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'temporary',
        'unit_price' => '42.5000',
    ]);
    $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id.'?unit_price=99.0000', [
        'type' => 'permanent',
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
    expect($contract->fresh()?->type->value)->toBe('temporary')
        ->and($contract->fresh()?->unit_price)->toBe('42.5000');

    $this->withToken($this->token)->postJson('/v1/contracts/'.$contract->id.'/retire?reason=caller-owned')
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($contract->fresh()?->status->value)->toBe('active');
});

test('inspect is tenant safe and distinguishes malformed UUID input', function (string $kind): void {
    grantContractApiPermissions($this, 'contracts.read');
    $id = 'not-a-uuid';
    if ($kind === 'local') {
        $id = Contract::factory()->create(['tenant_id' => $this->tenant->id])->id;
    } elseif ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = Contract::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    } elseif ($kind === 'missing') {
        $id = (string) Str::uuid();
    }

    $response = $this->withToken($this->token)->getJson('/v1/contracts/'.$id);
    if ($kind === 'local') {
        $response->assertOk()->assertJsonPath('data.id', $id);
    } elseif ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('contract');
    } else {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
})->with(['local', 'foreign', 'missing', 'malformed']);

test('PATCH is genuinely partial, supports clearing ends_on, and audits bounded changes', function (): void {
    grantContractApiPermissions($this, 'contracts.update');
    $contract = Contract::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'temporary',
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-31',
        'unit_price' => '42.5000',
    ]);

    $response = $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id, [
        'ends_on' => null,
        'unit_price' => '45.25',
    ]);

    $response->assertOk()->assertJsonPath('data.type', 'temporary')
        ->assertJsonPath('data.ends_on', null)->assertJsonPath('data.unit_price', '45.2500');
    $audit = Activity::query()->where('event', 'contract.update')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->causer_id)->toBe($this->actor->id)
        ->and($audit->subject_id)->toBe($contract->id)
        ->and($audit->properties->get('changed_fields'))->toBe(['ends_on', 'unit_price'])
        ->and($audit->properties->keys()->all())->toBe([
            'schema_version', 'operation', 'contract_id', 'changed_fields', 'changes',
        ])
        ->and($audit->properties->has('request'))->toBeFalse();
});

test('PATCH validates a non-empty closed body and the resulting date state', function (array $payload, string $field): void {
    grantContractApiPermissions($this, 'contracts.update');
    $contract = Contract::factory()->create([
        'tenant_id' => $this->tenant->id,
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-10',
    ]);

    $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'empty' => [[], 'contract'],
    'new end before existing start' => [['ends_on' => '2026-09-30'], 'ends_on'],
    'new start after existing end' => [['starts_on' => '2026-10-20'], 'ends_on'],
    'unknown' => [['status' => 'retired'], 'status'],
]);

test('PATCH maps lifecycle and booking-history conflicts without mutating state or auditing success', function (string $conflict): void {
    grantContractApiPermissions($this, 'contracts.update');
    $contract = Contract::factory()->create([
        'tenant_id' => $this->tenant->id,
        'currency_code' => 'EUR',
    ]);
    $replacement = visibleContractCustomer($this);
    $payload = ['customer_id' => $replacement->id];
    $message = 'The Contract customer cannot change after service booking history exists.';

    if ($conflict === 'retired') {
        $contract->update(['status' => 'retired', 'retired_at' => now()]);
        $payload = ['unit_price' => '50.0000'];
        $message = 'The Contract is retired.';
    } else {
        ServiceBooking::factory()->forContract($contract)->create();
        if ($conflict === 'currency') {
            $payload = ['currency_code' => 'USD'];
            $message = 'The Contract currency cannot change after service booking history exists.';
        }
    }

    $original = $contract->fresh()?->getAttributes();
    $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id, $payload)
        ->assertConflict()->assertExactJson(['message' => $message, 'code' => 'CONFLICT']);
    expect($contract->fresh()?->getAttributes())->toBe($original)
        ->and(Activity::query()->where('event', 'contract.update')->count())->toBe(0);
})->with(['retired', 'customer', 'currency']);

test('customer and currency can change before history and other commercial fields can change after history', function (): void {
    grantContractApiPermissions($this, 'contracts.update');
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id, 'currency_code' => 'EUR']);
    $replacement = visibleContractCustomer($this);

    $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id, [
        'customer_id' => $replacement->id,
        'currency_code' => 'USD',
    ])->assertOk();

    $booking = ServiceBooking::factory()->forContract($contract->fresh())->create([
        'billing_unit' => 'hour',
        'unit_price' => '42.5000',
        'currency_code' => 'USD',
    ]);
    $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id, [
        'type' => 'one_time',
        'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-01',
        'billing_unit' => 'flat',
        'unit_price' => '9999999999.9999',
    ])->assertOk()->assertJsonPath('data.unit_price', '9999999999.9999');

    $booking->refresh();
    expect($booking->billing_unit->value)->toBe('hour')
        ->and($booking->unit_price)->toBe('42.5000')
        ->and($booking->currency_code)->toBe('USD');
});

test('PATCH conceals an inaccessible replacement Customer', function (): void {
    grantContractApiPermissions($this, 'contracts.update');
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id]);
    $replacement = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)->patchJson('/v1/contracts/'.$contract->id, [
        'customer_id' => $replacement->id,
    ])->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    expect($contract->fresh()?->customer_id)->not->toBe($replacement->id);
});

test('retire is terminal, server-timestamped, bodyless, and audited once', function (): void {
    grantContractApiPermissions($this, 'contracts.retire');
    $contract = Contract::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->travelTo(now()->startOfSecond());

    $this->withToken($this->token)->postJson('/v1/contracts/'.$contract->id.'/retire')
        ->assertOk()->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.retired_at', now()->utc()->format('Y-m-d\TH:i:s\Z'));
    expect(Activity::query()->where('event', 'contract.retire')->count())->toBe(1);

    $this->withToken($this->token)->postJson('/v1/contracts/'.$contract->id.'/retire')
        ->assertConflict()
        ->assertExactJson(['message' => 'The Contract is retired.', 'code' => 'CONFLICT']);
    expect(Activity::query()->where('event', 'contract.retire')->count())->toBe(1);

    $active = Contract::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->withToken($this->token)->postJson('/v1/contracts/'.$active->id.'/retire', [
        'justification' => 'not accepted',
    ])->assertUnprocessable()->assertJsonValidationErrors('justification');
});

test('create produces one attributable bounded financial audit', function (): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customer = visibleContractCustomer($this);
    $response = $this->withToken($this->token)->postJson('/v1/contracts', validContractPayload($customer));
    $response->assertCreated();

    $audit = Activity::query()->where('event', 'contract.create')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->causer_id)->toBe($this->actor->id)
        ->and($audit->subject_id)->toBe($response->json('data.id'))
        ->and($audit->properties->get('contract_id'))->toBe($response->json('data.id'))
        ->and($audit->properties->get('changed_fields'))->toBe([
            'customer_id', 'type', 'starts_on', 'ends_on', 'billing_unit', 'unit_price', 'currency_code',
        ]);
});

test('a required audit failure rolls back the financial mutation', function (): void {
    grantContractApiPermissions($this, 'contracts.create');
    $customer = visibleContractCustomer($this);
    $this->mock(ContractAuditRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('recordCreate')->once()->andThrow(new RuntimeException('audit unavailable'));
    });

    $this->withToken($this->token)->postJson('/v1/contracts', validContractPayload($customer))
        ->assertInternalServerError();

    expect(Contract::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'contract.create')->count())->toBe(0);
});
