<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\LegalHoldStatus;
use App\Models\Activity;
use App\Models\ActivityArchive;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\TenantKey;
use App\Models\User;
use App\Repositories\LegalHoldRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('retention', 'legal-hold');

beforeEach(function (): void {
    $this->tenant = TenantKey::factory()->create();
});

/** @return array{0: LegalHold, 1: LegalHoldActivityAttachment} */
function activelyHoldActivity(TenantKey $tenant, Activity $activity): array
{
    $hold = LegalHold::factory()->active()->create(['tenant_id' => $tenant->id]);
    $attachment = LegalHoldActivityAttachment::factory()->attached()->create([
        'tenant_id' => $tenant->id,
        'legal_hold_id' => $hold->id,
        'activity_id' => $activity->id,
        'activity_identity_id' => $activity->id,
    ]);

    return [$hold, $attachment];
}

test('mixed retention preserves held chain truth while processing eligible activities', function (): void {
    $expiredAt = Carbon::now()->subYears(4)->startOfYear();
    $activityA = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Expired A',
        'created_at' => $expiredAt,
    ])->refresh();
    $activityB = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Held B',
        'created_at' => $expiredAt->copy()->addDay(),
    ])->refresh();
    $activityC = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Expired C',
        'created_at' => $expiredAt->copy()->addDays(2),
    ])->refresh();
    $activityD = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Current D',
        'created_at' => Carbon::now(),
    ])->refresh();
    activelyHoldActivity($this->tenant, $activityB);
    $heldPreviousHash = $activityB->previous_hash;
    $heldUpdatedAt = $activityB->updated_at;
    DB::enableQueryLog();

    $this->artisan('activity:apply-retention')
        ->expectsOutputToContain('Archived + deleted 2 logs')
        ->expectsOutputToContain('Skipped 1 actively held logs')
        ->assertSuccessful();

    $holdClassificationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(
            $query['query'],
            'activity_is_actively_held',
        ));
    $activityB->refresh();
    $activityD->refresh();
    $archiveA = ActivityArchive::query()->findOrFail($activityA->id);
    $archiveC = ActivityArchive::query()->findOrFail($activityC->id);

    expect(Activity::query()->whereKey($activityA->id)->exists())->toBeFalse()
        ->and(Activity::query()->whereKey($activityC->id)->exists())->toBeFalse()
        ->and($activityB->previous_hash)->toBe($heldPreviousHash)
        ->and($activityB->previous_hash)->toBe($activityA->event_hash)
        ->and($activityB->is_orphaned_genesis)->toBeFalse()
        ->and($activityB->updated_at?->equalTo($heldUpdatedAt))->toBeTrue()
        ->and($archiveC->previous_hash)->toBe($activityB->event_hash)
        ->and($activityD->is_orphaned_genesis)->toBeTrue()
        ->and($activityD->previous_hash)->toBeNull()
        ->and($holdClassificationQueries)->toHaveCount(2)
        ->and($archiveA->verifyChain())->toBeTrue()
        ->and($archiveC->verifyChain())->toBeTrue()
        ->and($activityB->verifyChain())->toBeTrue()
        ->and($activityB->verifyChainLink())->toBeTrue()
        ->and($activityD->verifyChain())->toBeTrue()
        ->and($activityD->verifyChainLink())->toBeTrue();
});

test('an unhashed expired activity does not orphan an unrelated genesis activity', function (): void {
    $expiredAt = Carbon::now()->subYears(4)->startOfYear();
    $unrelatedGenesis = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'financial_year_end',
        'created_at' => $expiredAt,
    ])->refresh();
    $expiredActivity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'created_at' => $expiredAt->copy()->addDay(),
    ])->refresh();
    DB::table('activity_log')
        ->where('tenant_id', $this->tenant->id)
        ->where('id', $expiredActivity->id)
        ->update(['event_hash' => null]);
    $genesisUpdatedAt = $unrelatedGenesis->updated_at;

    $this->artisan('activity:apply-retention')->assertSuccessful();

    $unrelatedGenesis->refresh();

    expect($expiredActivity->fresh())->toBeNull()
        ->and(ActivityArchive::query()->findOrFail($expiredActivity->id)->event_hash)->toBeNull()
        ->and($unrelatedGenesis->is_orphaned_genesis)->toBeFalse()
        ->and($unrelatedGenesis->previous_hash)->toBeNull()
        ->and($unrelatedGenesis->updated_at?->equalTo($genesisUpdatedAt))->toBeTrue();
});

test('detached attachment remains historical evidence without blocking retention', function (): void {
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => Carbon::now()->subYears(4)->startOfYear(),
    ]);
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create([
        'tenant_id' => $this->tenant->id,
        'activity_id' => $activity->id,
        'activity_identity_id' => $activity->id,
    ]);

    $this->artisan('activity:apply-retention')->assertSuccessful();

    expect($activity->fresh())->toBeNull()
        ->and(ActivityArchive::query()->find($activity->id))->not->toBeNull()
        ->and($attachment->fresh()?->activity_id)->toBeNull()
        ->and($attachment->fresh()?->activity_identity_id)->toBe($activity->id);
});

test('released hold restores retention eligibility after release commit', function (): void {
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => Carbon::now()->subYears(4)->startOfYear(),
    ]);
    [$hold, $attachment] = activelyHoldActivity($this->tenant, $activity);
    $releaser = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $hold->update([
        'status' => LegalHoldStatus::Released,
        'released_at' => now(),
        'released_by_user_id' => $releaser->id,
        'released_by_identity_id' => $releaser->id,
        'release_justification' => 'The proceeding has concluded.',
    ]);

    expect(app(LegalHoldRepository::class)->activityIsActivelyHeld($this->tenant->id, $activity->id))
        ->toBeFalse();

    $this->artisan('activity:apply-retention')->assertSuccessful();

    expect($activity->fresh())->toBeNull()
        ->and(ActivityArchive::query()->find($activity->id))->not->toBeNull()
        ->and($attachment->fresh()?->activity_id)->toBeNull()
        ->and($attachment->fresh()?->activity_identity_id)->toBe($activity->id);
});

test('another tenant active hold neither protects nor reveals local retention candidates', function (): void {
    $otherTenant = TenantKey::factory()->create();
    $foreignActivity = Activity::factory()->create([
        'tenant_id' => $otherTenant->id,
        'created_at' => Carbon::now()->subYears(4)->startOfYear(),
    ]);
    activelyHoldActivity($otherTenant, $foreignActivity);
    $localActivity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => Carbon::now()->subYears(4)->startOfYear(),
    ]);

    expect(app(LegalHoldRepository::class)->activityIsActivelyHeld(
        $this->tenant->id,
        $foreignActivity->id,
    ))->toBeFalse();

    $this->artisan('activity:apply-retention', ['--tenant' => $this->tenant->id])
        ->assertSuccessful();

    expect($localActivity->fresh())->toBeNull()
        ->and($foreignActivity->fresh())->not->toBeNull()
        ->and(ActivityArchive::query()->where('tenant_id', $otherTenant->id)->exists())->toBeFalse();
});

test('dry run reports eligible and held candidates without mutation', function (): void {
    $expiredAt = Carbon::now()->subYears(4)->startOfYear();
    $eligible = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => $expiredAt,
    ])->refresh();
    $held = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => $expiredAt->copy()->addDay(),
    ])->refresh();
    [, $attachment] = activelyHoldActivity($this->tenant, $held);
    $heldPreviousHash = $held->previous_hash;

    $this->artisan('activity:apply-retention', ['--dry-run' => true])
        ->expectsOutputToContain('Would archive and hard delete 1 logs')
        ->expectsOutputToContain('Would skip 1 actively held logs')
        ->expectsTable(
            ['Metric', 'Count'],
            [
                ['3-year retention: Archived + Deleted', 1],
                ['8-year retention: Archived + Deleted', 0],
                ['10-year retention: Archived + Deleted', 0],
                ['Actively held: Skipped', 1],
                ['Orphaned genesis created', 0],
                ['Total processed', 2],
            ],
        )
        ->assertSuccessful();

    expect($eligible->fresh())->not->toBeNull()
        ->and($held->fresh())->not->toBeNull()
        ->and($held->fresh()?->previous_hash)->toBe($heldPreviousHash)
        ->and($held->fresh()?->is_orphaned_genesis)->toBeFalse()
        ->and(ActivityArchive::query()->count())->toBe(0)
        ->and($attachment->fresh()?->activity_id)->toBe($held->id);
});

test('hold lookup failure fails closed before archive or deletion', function (): void {
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => Carbon::now()->subYears(4)->startOfYear(),
    ]);
    $repository = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $repository->shouldReceive('activelyHeldActivityIdentityIds')
        ->once()
        ->andThrow(new RuntimeException('Injected hold lookup failure.'));
    app()->instance(LegalHoldRepository::class, $repository);

    $this->artisan('activity:apply-retention')
        ->expectsOutputToContain('Error applying retention policies')
        ->assertFailed();

    expect($activity->fresh())->not->toBeNull()
        ->and(ActivityArchive::query()->find($activity->id))->toBeNull();
});

test('database authority blocks every direct held activity deletion surface', function (string $surface): void {
    $activity = Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'created_at' => Carbon::now()->subYears(4)->startOfYear(),
    ]);
    activelyHoldActivity($this->tenant, $activity);

    $delete = match ($surface) {
        'model' => fn () => DB::transaction(fn () => $activity->delete()),
        'builder' => fn () => DB::transaction(
            fn () => Activity::query()->whereKey($activity->id)->delete()
        ),
        'raw SQL' => fn () => DB::transaction(fn () => DB::delete(
            'DELETE FROM activity_log WHERE tenant_id = ? AND id = ?',
            [$this->tenant->id, $activity->id],
        )),
    };

    expect($delete)->toThrow(QueryException::class)
        ->and($activity->fresh())->not->toBeNull()
        ->and(ActivityArchive::query()->find($activity->id))->toBeNull();
})->with(['model', 'builder', 'raw SQL']);
