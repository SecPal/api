<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\LegalHoldAuditRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->token = $this->actor->createToken('legal-hold-api')->plainTextToken;
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Artisan::call('migrate:fresh', ['--force' => true]);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantLegalHoldApiPermissions(object $test, string ...$abilities): void
{
    foreach ($abilities as $ability) {
        givePermissionWithTenant($test->actor, $test->tenant->id, $ability);
    }
}

test('the API exposes exactly the six accepted legal hold operations', function (): void {
    $operations = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/legal-holds'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri())
            ->all())
        ->sort()->values()->all();

    expect($operations)->toBe([
        'GET v1/legal-holds',
        'GET v1/legal-holds/{legalHold}',
        'POST v1/legal-holds',
        'POST v1/legal-holds/{legalHold}/attachments',
        'POST v1/legal-holds/{legalHold}/attachments/{attachment}/detach',
        'POST v1/legal-holds/{legalHold}/release',
    ]);
});

test('the permission catalog contains only accepted legal hold capabilities and grants none to predefined roles', function (): void {
    expect(Permission::query()->where('name', 'like', 'legal_holds.%')->orderBy('name')->pluck('name')->all())
        ->toBe(['legal_holds.attach', 'legal_holds.create', 'legal_holds.detach', 'legal_holds.read', 'legal_holds.release'])
        ->and(Permission::query()->where('name', 'like', 'legal-hold:%')->exists())->toBeFalse()
        ->and(Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'like', 'legal_holds.%'))->exists())
        ->toBeFalse();
});

test('each legal hold operation requires its exact capability', function (string $method, string $uri): void {
    $this->withToken($this->token)->json($method, $uri)->assertForbidden();
})->with([
    ['GET', '/v1/legal-holds'],
    ['POST', '/v1/legal-holds'],
    ['GET', '/v1/legal-holds/11111111-1111-4111-8111-111111111111'],
    ['POST', '/v1/legal-holds/11111111-1111-4111-8111-111111111111/attachments'],
    ['POST', '/v1/legal-holds/11111111-1111-4111-8111-111111111111/attachments/22222222-2222-4222-8222-222222222222/detach'],
    ['POST', '/v1/legal-holds/11111111-1111-4111-8111-111111111111/release'],
]);

test('legal hold routes require authentication', function (): void {
    $this->getJson('/v1/legal-holds')->assertUnauthorized();
});

test('list is tenant isolated, deterministically ordered, paginated, and summary only', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.read');
    $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    LegalHold::factory()->create(['tenant_id' => $foreignTenant->id]);
    $timestamp = now()->subHour()->startOfSecond();
    LegalHold::factory()->count(16)->sequence(
        fn ($sequence): array => ['tenant_id' => $this->tenant->id, 'created_at' => $timestamp->copy()->subMinutes($sequence->index)],
    )->create();
    LegalHold::factory()->create(['tenant_id' => $this->tenant->id, 'created_at' => $timestamp]);

    $expected = LegalHold::query()->where('tenant_id', $this->tenant->id)
        ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->pluck('id')->all();
    $response = $this->withToken($this->token)->getJson('/v1/legal-holds');

    $response->assertOk()->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17);
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(array_keys($response->json('data.0')))->toBe([
            'id', 'case_reference', 'status', 'created_at', 'released_at',
        ]);
});

test('list validates pagination', function (string $query): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.read');
    $this->withToken($this->token)->getJson('/v1/legal-holds?'.$query)->assertUnprocessable();
})->with(['page=0', 'page=nope', 'per_page=0', 'per_page=101', 'per_page=nope']);

test('create trims input, derives the tenant, and returns minimal detail', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.create');
    $response = $this->withToken($this->token)->postJson('/v1/legal-holds', [
        'case_reference' => '  GENERAL/CIVIL 2026  ',
        'justification' => '  Preserve the selected evidence.  ',
    ]);

    $response->assertCreated()->assertJsonPath('data.case_reference', 'GENERAL/CIVIL 2026')
        ->assertJsonPath('data.justification', 'Preserve the selected evidence.')
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.released_at', null)
        ->assertJsonPath('data.release_justification', null)->assertJsonCount(0, 'data.attachments');
    expect(array_keys($response->json('data')))->toBe([
        'id', 'case_reference', 'status', 'created_at', 'released_at',
        'justification', 'release_justification', 'attachments',
    ]);
    $this->assertDatabaseHas('legal_holds', [
        'tenant_id' => $this->tenant->id, 'case_reference' => 'GENERAL/CIVIL 2026',
    ]);
});

test('create rejects invalid or unauthorized fields', function (string $failure, string $field): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.create');
    $payload = match ($failure) {
        'missing case reference' => ['justification' => 'Required reason.'],
        'blank case reference' => ['case_reference' => '   ', 'justification' => 'Required reason.'],
        'long case reference' => ['case_reference' => str_repeat('C', 65), 'justification' => 'Required reason.'],
        'blank justification' => ['case_reference' => 'CASE-1', 'justification' => '   '],
        'long justification' => ['case_reference' => 'CASE-1', 'justification' => str_repeat('J', 2001)],
        'caller tenant' => ['case_reference' => 'CASE-1', 'justification' => 'Required reason.', 'tenant_id' => 999],
        'server status' => ['case_reference' => 'CASE-1', 'justification' => 'Required reason.', 'status' => 'released'],
    };
    $this->withToken($this->token)->postJson('/v1/legal-holds', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['missing case reference', 'case_reference'],
    ['blank case reference', 'case_reference'],
    ['long case reference', 'case_reference'],
    ['blank justification', 'justification'],
    ['long justification', 'justification'],
    ['caller tenant', 'tenant_id'],
    ['server status', 'status'],
]);

test('duplicate case references return the stable conflict', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.create');
    LegalHold::factory()->create(['tenant_id' => $this->tenant->id, 'case_reference' => 'CASE-CONFLICT']);
    $this->withToken($this->token)->postJson('/v1/legal-holds', [
        'case_reference' => 'CASE-CONFLICT', 'justification' => 'Duplicate request.',
    ])->assertConflict()->assertExactJson([
        'message' => 'A Legal Hold with this case reference already exists.', 'code' => 'CONFLICT',
    ]);
});

test('inspect returns ordered immutable attachment history without internal data', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.read');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $this->tenant->id]);
    $attachedAt = now()->subDay()->startOfSecond();
    $higherId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    $lowerId = '11111111-1111-4111-8111-111111111111';
    foreach ([$higherId, $lowerId] as $id) {
        LegalHoldActivityAttachment::factory()->detached()->create([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'legal_hold_id' => $hold->id,
            'activity_id' => $activity->id, 'activity_identity_id' => $activity->id,
            'attached_at' => $attachedAt,
        ]);
    }
    $activity->delete();

    $response = $this->withToken($this->token)->getJson("/v1/legal-holds/{$hold->id}");
    $response->assertOk()->assertJsonPath('data.attachments.0.id', $lowerId)
        ->assertJsonPath('data.attachments.1.id', $higherId)
        ->assertJsonPath('data.attachments.0.activity_id', $activity->id)
        ->assertJsonMissingPath('data.tenant_id')->assertJsonMissingPath('data.created_by_user_id')
        ->assertJsonMissingPath('data.attachments.0.activity_identity_id')
        ->assertJsonMissingPath('data.attachments.0.activity');
    expect(array_keys($response->json('data.attachments.0')))->toBe([
        'id', 'activity_id', 'attached_at', 'detached_at', 'detachment_justification',
    ]);
});

test('malformed route UUIDs return validation errors', function (string $uri, string $field): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.read', 'legal_holds.detach', 'legal_holds.release');
    $this->withToken($this->token)->postJson($uri, ['justification' => 'Required reason.'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['/v1/legal-holds/not-a-uuid/release', 'legal_hold'],
    ['/v1/legal-holds/11111111-1111-4111-8111-111111111111/attachments/not-a-uuid/detach', 'attachment'],
]);

test('valid unavailable and foreign holds share the neutral response', function (bool $foreign): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.read');
    $id = (string) Str::uuid();
    if ($foreign) {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $id = LegalHold::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    }
    $this->withToken($this->token)->getJson('/v1/legal-holds/'.$id)->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
})->with([false, true]);

test('attach accepts one visible Activity and returns immutable identity', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.attach', 'activity_log.read');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $this->tenant->id]);
    $response = $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/attachments", [
        'activity_id' => $activity->id,
    ]);
    $response->assertCreated()->assertJsonPath('data.activity_id', $activity->id)
        ->assertJsonPath('data.detached_at', null);
    expect(array_keys($response->json('data')))->toBe([
        'id', 'activity_id', 'attached_at', 'detached_at', 'detachment_justification',
    ]);
});

test('attach conceals nonexistent, foreign, and policy-invisible Activities', function (string $target): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.attach', 'activity_log.read');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $activityId = PHP_INT_MAX;
    if ($target === 'foreign') {
        $foreignTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $activityId = Activity::factory()->create(['tenant_id' => $foreignTenant->id])->id;
    } elseif ($target === 'invisible') {
        $unit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
        $activityId = Activity::factory()->create([
            'tenant_id' => $this->tenant->id, 'organizational_unit_id' => $unit->id,
        ])->id;
    }
    $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/attachments", [
        'activity_id' => $activityId,
    ])->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
    expect(LegalHoldActivityAttachment::query()->count())->toBe(0);
})->with(['nonexistent', 'foreign', 'invisible']);

test('attach validates its closed single-Activity body', function (array $payload, string $field): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.attach');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/attachments", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing' => [[], 'activity_id'],
    'zero' => [['activity_id' => 0], 'activity_id'],
    'array' => [['activity_id' => [1]], 'activity_id'],
    'bulk alias' => [['activity_id' => 1, 'activity_ids' => [1]], 'activity_ids'],
]);

test('attach maps duplicate and released-hold conflicts', function (string $conflict): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.attach', 'activity_log.read');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/attachments", [
        'activity_id' => $activity->id,
    ])->assertCreated();
    if ($conflict === 'released') {
        DB::table('legal_holds')->where('id', $hold->id)->update([
            'status' => 'released', 'released_at' => now(),
            'released_by_user_id' => $this->actor->id,
            'released_by_identity_id' => $this->actor->id,
            'release_justification' => 'Released outside this request.',
        ]);
    }
    $expected = $conflict === 'released' ? 'The Legal Hold is not active.'
        : 'The Activity is already actively attached to this Legal Hold.';
    $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/attachments", [
        'activity_id' => $activity->id,
    ])->assertConflict()->assertExactJson(['message' => $expected, 'code' => 'CONFLICT']);
})->with(['duplicate', 'released']);

test('detach preserves history without requiring Activity visibility', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.detach');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $unit = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id, 'organizational_unit_id' => $unit->id,
    ]);
    $attachment = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $this->tenant->id, 'legal_hold_id' => $hold->id,
        'activity_id' => $activity->id, 'activity_identity_id' => $activity->id,
    ]);
    $response = $this->withToken($this->token)->postJson(
        "/v1/legal-holds/{$hold->id}/attachments/{$attachment->id}/detach",
        ['justification' => '  Evidence left the proceeding scope.  '],
    );
    $response->assertOk()->assertJsonPath('data.activity_id', $activity->id)
        ->assertJsonPath('data.detachment_justification', 'Evidence left the proceeding scope.');
    expect($attachment->fresh()?->detached_at)->not->toBeNull()
        ->and(LegalHoldActivityAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

test('detach maps repeated and wrong-hold attachment failures', function (string $failure): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.detach');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $attachment = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $this->tenant->id, 'legal_hold_id' => $hold->id,
    ]);
    if ($failure === 'detached') {
        $uri = "/v1/legal-holds/{$hold->id}/attachments/{$attachment->id}/detach";
        $this->withToken($this->token)->postJson($uri, ['justification' => 'First detachment.'])->assertOk();
        $this->withToken($this->token)->postJson($uri, ['justification' => 'Second detachment.'])
            ->assertConflict()->assertExactJson([
                'message' => 'The attachment is already detached.', 'code' => 'CONFLICT',
            ]);

        return;
    }
    $wrongHold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->withToken($this->token)->postJson(
        "/v1/legal-holds/{$wrongHold->id}/attachments/{$attachment->id}/detach",
        ['justification' => 'Wrong hold.'],
    )->assertNotFound()->assertExactJson(['message' => 'Resource not found', 'code' => 'NOT_FOUND']);
})->with(['detached', 'wrong hold']);

test('detach and release require closed bounded justification bodies', function (string $suffix, string $failure, string $field): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.detach', 'legal_holds.release');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $attachment = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $this->tenant->id, 'legal_hold_id' => $hold->id,
    ]);
    $uri = $suffix === 'release' ? "/v1/legal-holds/{$hold->id}/release"
        : "/v1/legal-holds/{$hold->id}/attachments/{$attachment->id}/detach";
    $payload = match ($failure) {
        'missing' => [],
        'blank' => ['justification' => '   '],
        'long' => ['justification' => str_repeat('J', 2001)],
        'release field' => ['justification' => 'Valid.', 'released_at' => '2026-09-09T18:00:00Z'],
        'activity field' => ['justification' => 'Valid.', 'activity_id' => 1],
    };
    $this->withToken($this->token)->postJson($uri, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['release', 'missing', 'justification'],
    ['release', 'blank', 'justification'],
    ['release', 'long', 'justification'],
    ['release', 'release field', 'released_at'],
    ['detach', 'activity field', 'activity_id'],
]);

test('release returns detail, conflicts on repetition, and does not run retention', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.attach', 'legal_holds.detach', 'legal_holds.release', 'activity_log.read');
    $hold = LegalHold::factory()->create(['tenant_id' => $this->tenant->id]);
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id, 'created_at' => now()->subYears(4),
    ]);
    $attachmentId = (string) $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/attachments", [
        'activity_id' => $activity->id,
    ])->assertCreated()->json('data.id');
    $response = $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/release", [
        'justification' => 'The proceeding has concluded.',
    ]);
    $response->assertOk()->assertJsonPath('data.status', 'released')
        ->assertJsonPath('data.release_justification', 'The proceeding has concluded.')
        ->assertJsonCount(1, 'data.attachments');
    expect(Activity::query()->whereKey($activity->id)->exists())->toBeTrue();
    $this->withToken($this->token)->postJson(
        "/v1/legal-holds/{$hold->id}/attachments/{$attachmentId}/detach",
        ['justification' => 'Too late.'],
    )->assertConflict()->assertExactJson([
        'message' => 'The Legal Hold is not active.', 'code' => 'CONFLICT',
    ]);
    $this->withToken($this->token)->postJson("/v1/legal-holds/{$hold->id}/release", [
        'justification' => 'Second release.',
    ])->assertConflict()->assertExactJson([
        'message' => 'The Legal Hold is not active.', 'code' => 'CONFLICT',
    ]);
});

test('required audit failure is neutral and rolls back the mutation', function (): void {
    grantLegalHoldApiPermissions($this, 'legal_holds.create');
    $this->mock(LegalHoldAuditRecorder::class, function (MockInterface $mock): void {
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('sensitive audit failure'));
    });
    $this->withToken($this->token)->postJson('/v1/legal-holds', [
        'case_reference' => 'CASE-AUDIT-FAILURE', 'justification' => 'Must roll back.',
    ])->assertInternalServerError()->assertExactJson(['message' => 'Internal server error.']);
    expect(LegalHold::query()->where('case_reference', 'CASE-AUDIT-FAILURE')->exists())->toBeFalse();
});
