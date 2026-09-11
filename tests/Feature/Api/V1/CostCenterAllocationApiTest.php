<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\CostCenterAllocationConflictException;
use App\Models\Activity;
use App\Models\CostCenterAllocation;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\CostCenterAllocationAuditRecorder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
    $this->token = $this->actor->createToken('allocation-api')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantAllocationApiPermissions(object $test, string ...$abilities): void
{
    foreach ($abilities as $ability) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $ability);
    }
}

/** @param list<array{internal_cost_center_id: string, share_bps: mixed}> $allocations */
function allocationPayload(array $allocations): array
{
    return ['allocations' => $allocations];
}

test('allocation routes require authentication and their own exact capability', function (): void {
    $uri = '/v1/service-bookings/11111111-1111-4111-8111-111111111111/cost-center-allocations';
    $this->getJson($uri)->assertUnauthorized();
    $this->withToken($this->token)->getJson($uri)->assertForbidden();
    $this->withToken($this->token)->putJson($uri, ['allocations' => []])->assertForbidden();
});

test('GET returns the exact deterministic snapshot without persistence identity', function (): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.read');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $allocations = CostCenterAllocation::factory()->createCompleteSplit($booking, [4000, 6000]);
    $expected = $allocations->sortBy('internal_cost_center_id', SORT_STRING)->values()
        ->map(fn (CostCenterAllocation $allocation): array => [
            'internal_cost_center_id' => $allocation->internal_cost_center_id,
            'share_bps' => $allocation->share_bps,
        ])->all();

    $response = $this->withToken($this->token)->getJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
    );

    $response->assertExactJson(['data' => [
        'service_booking_id' => $booking->id,
        'allocations' => $expected,
    ]]);
});

test('GET returns an empty snapshot and conceals unavailable bookings', function (string $kind): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.read');
    $id = 'not-a-uuid';
    if ($kind === 'local') {
        $id = ServiceBooking::factory()->forTenant($this->tenant->id)->create()->id;
    } elseif ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = ServiceBooking::factory()->forTenant($foreignTenant->id)->create()->id;
    } elseif ($kind === 'missing') {
        $id = (string) Str::uuid();
    }

    $response = $this->withToken($this->token)->getJson('/v1/service-bookings/'.$id.'/cost-center-allocations');
    if ($kind === 'local') {
        $response->assertExactJson(['data' => ['service_booking_id' => $id, 'allocations' => []]]);
    } elseif ($kind === 'malformed') {
        $response->assertUnprocessable()->assertJsonValidationErrors('serviceBooking');
    } else {
        $response->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
})->with(['local', 'foreign', 'missing', 'malformed']);

test('PUT accepts every authoritative complete split and returns committed ordered state', function (array $shares): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $centers = InternalCostCenter::factory()->count(count($shares))->create(['tenant_id' => $this->tenant->id]);
    $items = collect($shares)->map(fn (int $share, int $index): array => [
        'internal_cost_center_id' => $centers[$index]->id,
        'share_bps' => $share,
    ])->reverse()->values()->all();

    $response = $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload($items),
    );

    $response->assertOk()->assertJsonPath('data.service_booking_id', $booking->id);
    expect(collect($response->json('data.allocations'))->pluck('internal_cost_center_id')->all())
        ->toBe($centers->pluck('id')->sort()->values()->all())
        ->and(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->sum('share_bps'))
        ->toBe(array_sum($shares));
})->with([
    'empty' => [[]],
    'whole' => [[10000]],
    'halves' => [[5000, 5000]],
    'thirds' => [[3333, 3333, 3334]],
]);

test('PUT complete replacement removes all old rows and records one bounded audit', function (): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    CostCenterAllocation::factory()->createCompleteSplit($booking, [6000, 4000]);
    $newCenters = InternalCostCenter::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    $commercial = $booking->refresh()->getRawOriginal();

    $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload([
            ['internal_cost_center_id' => $newCenters[0]->id, 'share_bps' => 5000],
            ['internal_cost_center_id' => $newCenters[1]->id, 'share_bps' => 3000],
            ['internal_cost_center_id' => $newCenters[2]->id, 'share_bps' => 2000],
        ]),
    )->assertOk();

    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)
        ->orderBy('internal_cost_center_id')->pluck('share_bps', 'internal_cost_center_id')->all())
        ->toBe($newCenters->mapWithKeys(fn (InternalCostCenter $center, int $index): array => [
            $center->id => [5000, 3000, 2000][$index],
        ])->sortKeys()->all())
        ->and($booking->fresh()?->getRawOriginal())->toBe($commercial);
    $audit = Activity::query()->where('event', 'cost_center_allocation.replace')->sole();
    expect($audit->properties->keys()->all())->toBe([
        'schema_version', 'operation', 'service_booking_id', 'previous_allocations', 'allocations',
    ]);
});

test('PUT rejects malformed split shapes before mutation', function (array $payload, string $field): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();

    $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        $payload,
    )->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->count())->toBe(0);
})->with(function (): array {
    $id = '11111111-1111-4111-8111-111111111111';

    return [
        'missing top level' => [[], 'allocations'],
        'extra top level' => [['allocations' => [], 'tenant_id' => 1], 'tenant_id'],
        'not array' => [['allocations' => 'none'], 'allocations'],
        'missing target' => [allocationPayload([['share_bps' => 10000]]), 'allocations.0.internal_cost_center_id'],
        'malformed target' => [allocationPayload([['internal_cost_center_id' => 'bad', 'share_bps' => 10000]]), 'allocations.0.internal_cost_center_id'],
        'missing share' => [allocationPayload([['internal_cost_center_id' => $id]]), 'allocations.0.share_bps'],
        'zero' => [allocationPayload([['internal_cost_center_id' => $id, 'share_bps' => 0]]), 'allocations.0.share_bps'],
        'negative' => [allocationPayload([['internal_cost_center_id' => $id, 'share_bps' => -1]]), 'allocations.0.share_bps'],
        'fractional' => [allocationPayload([['internal_cost_center_id' => $id, 'share_bps' => 9999.5]]), 'allocations.0.share_bps'],
        'too large item' => [allocationPayload([['internal_cost_center_id' => $id, 'share_bps' => 10001]]), 'allocations.0.share_bps'],
        'incomplete' => [allocationPayload([['internal_cost_center_id' => $id, 'share_bps' => 5000]]), 'allocations'],
        'over total' => [allocationPayload([
            ['internal_cost_center_id' => $id, 'share_bps' => 6000],
            ['internal_cost_center_id' => '22222222-2222-4222-8222-222222222222', 'share_bps' => 5000],
        ]), 'allocations'],
        'duplicate target' => [allocationPayload([
            ['internal_cost_center_id' => $id, 'share_bps' => 5000],
            ['internal_cost_center_id' => $id, 'share_bps' => 5000],
        ]), 'allocations.1.internal_cost_center_id'],
        'extra item field' => [allocationPayload([[
            'internal_cost_center_id' => $id, 'share_bps' => 10000, 'id' => $id,
        ]]), 'allocations.0'],
    ];
});

test('PUT rejects integer-like string shares before mutation', function (): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();

    $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload([['internal_cost_center_id' => $center->id, 'share_bps' => '10000']]),
    )->assertUnprocessable()->assertJsonValidationErrors('allocations.0.share_bps');

    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->count())->toBe(0);
});

test('PUT rejects object-shaped allocation collections without clearing the snapshot', function (string $allocations): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $original = CostCenterAllocation::factory()->createCompleteSplit($booking)->sole();

    $this->call(
        'PUT',
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ],
        content: '{"allocations":'.$allocations.'}',
    )->assertUnprocessable()->assertJsonValidationErrors('allocations');

    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->sole()->id)
        ->toBe($original->id);
})->with([
    'empty object' => ['{}'],
    'keyed object' => ['{"target":{"internal_cost_center_id":"11111111-1111-4111-8111-111111111111","share_bps":10000}}'],
]);

test('PUT rejects differently cased spellings of the same allocation target', function (): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();

    $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload([
            ['internal_cost_center_id' => strtolower($center->id), 'share_bps' => 5000],
            ['internal_cost_center_id' => strtoupper($center->id), 'share_bps' => 5000],
        ]),
    )->assertUnprocessable()->assertJsonValidationErrors('allocations.1.internal_cost_center_id');

    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->count())->toBe(0);
});

test('concurrent allocation conflicts use the accepted closed message', function (): void {
    $request = Request::create(
        '/v1/service-bookings/11111111-1111-4111-8111-111111111111/cost-center-allocations',
        'PUT',
    );
    $response = app(ExceptionHandler::class)->render($request, new CostCenterAllocationConflictException);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getContent())->json()->toBe([
            'message' => 'The allocation could not be replaced because authoritative state changed concurrently.',
            'code' => 'CONFLICT',
        ]);
});

test('PUT conceals foreign and nonexistent centers and rejects inactive centers atomically', function (string $kind): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $original = CostCenterAllocation::factory()->createCompleteSplit($booking)->sole();
    $id = (string) Str::uuid();
    if ($kind === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = InternalCostCenter::factory()->forTenant($foreignTenant->id)->create()->id;
    } elseif ($kind === 'inactive') {
        $id = InternalCostCenter::factory()->forTenant($this->tenant->id)->inactive()->create()->id;
    }

    $response = $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload([['internal_cost_center_id' => $id, 'share_bps' => 10000]]),
    );

    if ($kind === 'inactive') {
        $response->assertConflict()->assertExactJson([
            'message' => 'An allocation target is inactive.', 'code' => 'CONFLICT',
        ]);
    } else {
        $response->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    }
    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->sole()->id)
        ->toBe($original->id);
})->with(['missing', 'foreign', 'inactive']);

test('PUT remains valid for invoiced and retired bookings without mutating booking facts', function (string $state): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $factory = ServiceBooking::factory()->forTenant($this->tenant->id);
    $booking = $state === 'invoiced' ? $factory->invoiced()->create() : $factory->retired()->create();
    $center = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();
    $before = $booking->refresh()->getRawOriginal();

    $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload([['internal_cost_center_id' => $center->id, 'share_bps' => 10000]]),
    )->assertOk();

    expect($booking->fresh()?->getRawOriginal())->toBe($before);
})->with(['invoiced', 'retired']);

test('required audit failure rolls back the original snapshot', function (): void {
    grantAllocationApiPermissions($this, 'cost_center_allocations.update');
    $booking = ServiceBooking::factory()->forTenant($this->tenant->id)->create();
    $original = CostCenterAllocation::factory()->createCompleteSplit($booking)->sole();
    $newCenter = InternalCostCenter::factory()->forTenant($this->tenant->id)->create();
    $this->mock(CostCenterAllocationAuditRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('snapshot')->twice()->andReturn([]);
        $mock->shouldReceive('recordReplace')->once()->andThrow(new RuntimeException('audit failure'));
    });

    $this->withToken($this->token)->putJson(
        '/v1/service-bookings/'.$booking->id.'/cost-center-allocations',
        allocationPayload([['internal_cost_center_id' => $newCenter->id, 'share_bps' => 10000]]),
    )->assertInternalServerError()->assertJsonMissing(['message' => 'audit failure']);

    expect(CostCenterAllocation::query()->where('service_booking_id', $booking->id)->sole()->id)
        ->toBe($original->id);
});
