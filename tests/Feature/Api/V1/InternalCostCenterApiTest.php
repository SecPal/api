<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\CostCenterAllocation;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->actor->createToken('internal-cost-center-api')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantInternalCostCenterApiPermissions(object $test, string ...$abilities): void
{
    foreach ($abilities as $ability) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $ability);
    }
}

test('Internal Cost Center routes require authentication and their exact capability', function (): void {
    $this->getJson('/v1/internal-cost-centers')->assertUnauthorized();

    foreach ([
        ['GET', '/v1/internal-cost-centers'],
        ['POST', '/v1/internal-cost-centers'],
        ['GET', '/v1/internal-cost-centers/11111111-1111-4111-8111-111111111111'],
        ['PATCH', '/v1/internal-cost-centers/11111111-1111-4111-8111-111111111111'],
        ['POST', '/v1/internal-cost-centers/11111111-1111-4111-8111-111111111111/deactivate'],
    ] as [$method, $uri]) {
        $this->withToken($this->token)->json($method, $uri)->assertForbidden();
    }
});

test('list is tenant isolated, includes both states, and uses deterministic pagination', function (): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.read');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    InternalCostCenter::factory()->forTenant($foreignTenant->id)->create();
    $createdAt = now()->subHour()->startOfSecond();
    InternalCostCenter::factory()->count(16)->sequence(
        fn ($sequence): array => [
            'tenant_id' => $this->tenant->id,
            'created_at' => $createdAt->copy()->subMinutes($sequence->index),
        ],
    )->create();
    InternalCostCenter::factory()->forTenant($this->tenant->id)->inactive()->create([
        'created_at' => $createdAt,
    ]);

    $expected = InternalCostCenter::query()->forTenant($this->tenant->id)
        ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->pluck('id')->all();
    $response = $this->withToken($this->token)->getJson('/v1/internal-cost-centers');

    $response->assertOk()->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(collect($response->json('data'))->pluck('status'))->toContain('inactive');
});

test('list validates pagination and rejects unsupported query fields', function (string $query): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.read');

    $this->withToken($this->token)->getJson('/v1/internal-cost-centers?'.$query)->assertUnprocessable();
})->with([
    'page=0', 'page=nope', 'per_page=0', 'per_page=101', 'per_page=nope',
    'status=active', 'search=ops', 'sort=id', 'tenant_id=999',
]);

test('create derives lifecycle and tenant, preserves code, serializes exactly, and audits once', function (): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.create');
    $this->travelTo(now()->startOfSecond());

    $response = $this->withToken($this->token)->postJson('/v1/internal-cost-centers', [
        'code' => ' Ops North ',
        'name' => 'Operations North',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', ' Ops North ')
        ->assertJsonPath('data.name', 'Operations North')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.inactive_at', null)
        ->assertJsonMissingPath('data.tenant_id');
    expect(array_keys($response->json('data')))->toBe([
        'id', 'code', 'name', 'status', 'inactive_at', 'created_at', 'updated_at',
    ])->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $center = InternalCostCenter::query()->sole();
    expect($center->tenant_id)->toBe($this->tenant->id)
        ->and($center->code)->toBe(' Ops North ');
    $audit = Activity::query()->where('event', 'internal_cost_center.create')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->subject_id)->toBe($center->id)
        ->and($audit->properties->keys()->all())->toBe([
            'schema_version', 'operation', 'internal_cost_center_id', 'changed_fields', 'changes',
        ]);
});

test('codes are unique only inside their tenant', function (): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.create');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    InternalCostCenter::factory()->forTenant($foreignTenant->id)->create(['code' => 'OPS-1']);

    $this->withToken($this->token)->postJson('/v1/internal-cost-centers', [
        'code' => 'OPS-1', 'name' => 'Local valid',
    ])->assertCreated();

    $this->withToken($this->token)->postJson('/v1/internal-cost-centers', [
        'code' => 'OPS-1', 'name' => 'Duplicate',
    ])->assertConflict()->assertJsonPath('code', 'CONFLICT');
});

test('create enforces exact closed nonblank request fields', function (array $payload, string $field): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.create');

    $this->withToken($this->token)->postJson('/v1/internal-cost-centers', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing code' => [['name' => 'Name'], 'code'],
    'blank code' => [['code' => '   ', 'name' => 'Name'], 'code'],
    'long code' => [['code' => str_repeat('x', 65), 'name' => 'Name'], 'code'],
    'missing name' => [['code' => 'CODE'], 'name'],
    'blank name' => [['code' => 'CODE', 'name' => '   '], 'name'],
    'long name' => [['code' => 'CODE', 'name' => str_repeat('x', 256)], 'name'],
    'id' => [['code' => 'CODE', 'name' => 'Name', 'id' => '11111111-1111-4111-8111-111111111111'], 'id'],
    'tenant' => [['code' => 'CODE', 'name' => 'Name', 'tenant_id' => 7], 'tenant_id'],
    'status' => [['code' => 'CODE', 'name' => 'Name', 'status' => 'inactive'], 'status'],
    'inactive timestamp' => [['code' => 'CODE', 'name' => 'Name', 'inactive_at' => now()->toIso8601String()], 'inactive_at'],
    'unknown' => [['code' => 'CODE', 'name' => 'Name', 'allocation' => []], 'allocation'],
]);

test('inspect is tenant safe and distinguishes malformed UUID input', function (string $kind): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.read');
    $id = 'not-a-uuid';
    if ($kind === 'local') {
        $id = InternalCostCenter::factory()->forTenant($this->tenant->id)->create()->id;
    } elseif ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = InternalCostCenter::factory()->forTenant($foreignTenant->id)->create()->id;
    } elseif ($kind === 'missing') {
        $id = (string) Str::uuid();
    }

    $response = $this->withToken($this->token)->getJson('/v1/internal-cost-centers/'.$id);
    if ($kind === 'local') {
        $response->assertOk()->assertJsonPath('data.id', $id);
    } elseif ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('internalCostCenter');
    } else {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
})->with(['local', 'foreign', 'missing', 'malformed']);

test('PATCH changes only active center name and audits committed state', function (): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.update');
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create([
        'code' => 'IMMUTABLE', 'name' => 'Before',
    ]);

    $this->withToken($this->token)->patchJson('/v1/internal-cost-centers/'.$center->id, [
        'name' => 'After',
    ])->assertOk()->assertJsonPath('data.name', 'After')->assertJsonPath('data.code', 'IMMUTABLE');

    $audit = Activity::query()->where('event', 'internal_cost_center.update')->sole();
    expect($center->fresh()?->name)->toBe('After')
        ->and($audit->properties->get('changed_fields'))->toBe(['name']);
});

test('PATCH rejects empty, immutable, server-owned, and unknown fields', function (array $payload, string $field): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.update');
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();

    $this->withToken($this->token)->patchJson('/v1/internal-cost-centers/'.$center->id, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'empty' => [[], 'internalCostCenter'],
    'code' => [['code' => 'NEW'], 'code'],
    'status' => [['status' => 'inactive'], 'status'],
    'inactive timestamp' => [['inactive_at' => now()->toIso8601String()], 'inactive_at'],
    'tenant' => [['tenant_id' => 7], 'tenant_id'],
    'unknown' => [['allocation' => []], 'allocation'],
]);

test('inactive PATCH and repeated deactivation are conflicts while historical allocations survive', function (): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.update', 'internal_cost_centers.deactivate');
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    CostCenterAllocation::factory()->forServiceBooking($booking)->forCostCenter($center)->complete()->create();

    $this->call(
        'POST',
        '/v1/internal-cost-centers/'.$center->id.'/deactivate',
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ],
    )
        ->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->withToken($this->token)->patchJson('/v1/internal-cost-centers/'.$center->id, ['name' => 'No'])
        ->assertConflict()->assertJsonPath('code', 'CONFLICT');
    $this->call(
        'POST',
        '/v1/internal-cost-centers/'.$center->id.'/deactivate',
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ],
    )
        ->assertConflict()->assertJsonPath('code', 'CONFLICT');

    expect(CostCenterAllocation::query()->where('internal_cost_center_id', $center->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'internal_cost_center.deactivate')->count())->toBe(1);
});

test('deactivate rejects any body and malformed UUIDs', function (): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.deactivate');
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();

    $this->withToken($this->token)->postJson('/v1/internal-cost-centers/'.$center->id.'/deactivate', ['reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->call(
        'POST',
        '/v1/internal-cost-centers/'.$center->id.'/deactivate',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ],
        content: '{}',
    )->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->call(
        'POST',
        '/v1/internal-cost-centers/not-a-uuid/deactivate',
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ],
    )
        ->assertUnprocessable()->assertJsonValidationErrors('internalCostCenter');
});

test('mutations conceal foreign and nonexistent valid Internal Cost Center IDs', function (string $operation, string $kind): void {
    grantInternalCostCenterApiPermissions($this, 'internal_cost_centers.update', 'internal_cost_centers.deactivate');
    $id = (string) Str::uuid();
    if ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = InternalCostCenter::factory()->forTenant($foreignTenant->id)->create()->id;
    }

    $response = $operation === 'update'
        ? $this->withToken($this->token)->patchJson('/v1/internal-cost-centers/'.$id, ['name' => 'Hidden'])
        : $this->call(
            'POST',
            '/v1/internal-cost-centers/'.$id.'/deactivate',
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
        );

    $response->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
})->with([
    ['update', 'foreign'], ['update', 'missing'],
    ['deactivate', 'foreign'], ['deactivate', 'missing'],
]);
