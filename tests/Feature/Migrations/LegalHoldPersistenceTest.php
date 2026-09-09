<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\LegalHoldStatus;
use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\TenantKey;
use App\Models\User;
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

/** @param array<string, mixed> $overrides */
function legalHoldRow(TenantKey $tenant, User $creator, array $overrides = []): array
{
    return array_replace([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $tenant->id,
        'case_reference' => 'CASE-'.Str::upper(Str::random(12)),
        'status' => 'active',
        'justification' => 'Preserve evidence for the identified proceeding.',
        'created_by_user_id' => $creator->id,
        'created_by_identity_id' => $creator->id,
        'released_at' => null,
        'released_by_user_id' => null,
        'released_by_identity_id' => null,
        'release_justification' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

test('creates the canonical PostgreSQL legal hold persistence schema and constraints', function (): void {
    expect((int) DB::scalar('SHOW server_version_num'))->toBeGreaterThanOrEqual(180000)
        ->and(Schema::hasColumns('legal_holds', [
            'id', 'tenant_id', 'case_reference', 'status', 'justification',
            'created_by_user_id', 'created_by_identity_id', 'released_at',
            'released_by_user_id', 'released_by_identity_id', 'release_justification',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('legal_hold_activity_attachments', [
            'id', 'tenant_id', 'legal_hold_id', 'activity_id', 'activity_identity_id', 'attached_by_user_id',
            'attached_by_identity_id', 'attached_at', 'detached_at',
            'detached_by_user_id', 'detached_by_identity_id', 'detachment_justification',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $constraints = DB::table('pg_constraint')
        ->whereRaw('connamespace = current_schema()::regnamespace')
        ->whereIn('conname', [
            'legal_holds_tenant_case_reference_unique',
            'legal_holds_status_check',
            'legal_holds_lifecycle_check',
            'legal_holds_creator_tenant_user_foreign',
            'legal_holds_releaser_tenant_user_foreign',
            'legal_hold_attachments_tenant_hold_foreign',
            'legal_hold_attachments_tenant_activity_foreign',
            'legal_hold_attachments_attacher_tenant_user_foreign',
            'legal_hold_attachments_detacher_tenant_user_foreign',
            'legal_hold_attachments_lifecycle_check',
        ])
        ->pluck('conname')
        ->all();

    $creatorForeignKey = (string) DB::table('pg_constraint')
        ->whereRaw('connamespace = current_schema()::regnamespace')
        ->where('conname', 'legal_holds_creator_tenant_user_foreign')
        ->selectRaw('pg_get_constraintdef(oid) AS definition')
        ->value('definition');

    expect($constraints)->toHaveCount(10)
        ->and(DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', 'legal_hold_attachments_active_identity_unique')
            ->exists())->toBeTrue()
        ->and(DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', 'legal_hold_attachments_active_activity_identity_index')
            ->exists())->toBeTrue()
        ->and((bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_trigger
                WHERE tgname = 'activity_log_prevent_actively_held_delete'
                    AND NOT tgisinternal
            )
            SQL))->toBeTrue()
        ->and($creatorForeignKey)->toContain(
            'FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users(tenant_id, id)'
        );
});

test('scopes stable case references to a tenant', function (): void {
    $tenantA = TenantKey::factory()->create();
    $tenantB = TenantKey::factory()->create();
    $creatorA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $creatorB = User::factory()->create(['tenant_id' => $tenantB->id]);

    DB::table('legal_holds')->insert(legalHoldRow($tenantA, $creatorA, ['case_reference' => 'CASE-449']));
    DB::table('legal_holds')->insert(legalHoldRow($tenantB, $creatorB, ['case_reference' => 'CASE-449']));

    expect(DB::table('legal_holds')->where('case_reference', 'CASE-449')->count())->toBe(2);

    DB::table('legal_holds')->insert(legalHoldRow($tenantA, $creatorA, ['case_reference' => 'CASE-449']));
})->throws(QueryException::class);

test('defaults required case creation timestamps at the database boundary', function (): void {
    $tenant = TenantKey::factory()->create();
    $creator = User::factory()->create(['tenant_id' => $tenant->id]);
    $row = legalHoldRow($tenant, $creator);
    unset($row['created_at'], $row['updated_at']);

    DB::table('legal_holds')->insert($row);

    expect(DB::table('legal_holds')->where('id', $row['id'])->value('created_at'))->not->toBeNull();
});

test('rejects invalid and contradictory legal hold lifecycle state', function (array $overrides): void {
    $tenant = TenantKey::factory()->create();
    $creator = User::factory()->create(['tenant_id' => $tenant->id]);

    DB::table('legal_holds')->insert(legalHoldRow($tenant, $creator, $overrides));
})->with([
    'unknown status' => [['status' => 'expired']],
    'active with release timestamp' => [['released_at' => now()]],
    'released without evidence' => [['status' => 'released']],
    'released without justification' => [[
        'status' => 'released',
        'released_at' => now(),
        'released_by_identity_id' => Str::uuid()->toString(),
    ]],
])->throws(QueryException::class);

test('rejects cross-tenant case and attachment actors at the database boundary', function (string $target): void {
    $tenant = TenantKey::factory()->create();
    $otherTenant = TenantKey::factory()->create();
    $creator = User::factory()->create(['tenant_id' => $tenant->id]);
    $foreignActor = User::factory()->create(['tenant_id' => $otherTenant->id]);

    if ($target === 'case') {
        DB::table('legal_holds')->insert(legalHoldRow($tenant, $creator, [
            'created_by_user_id' => $foreignActor->id,
            'created_by_identity_id' => $foreignActor->id,
        ]));

        return;
    }

    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_hold_id' => $hold->id,
        'activity_id' => $activity->id,
        'attached_by_user_id' => $foreignActor->id,
        'attached_by_identity_id' => $foreignActor->id,
    ]);
})->with(['case', 'attachment'])->throws(QueryException::class);

test('prevents referenced actors from moving across tenant boundaries', function (): void {
    $hold = LegalHold::factory()->create();
    $otherTenant = TenantKey::factory()->create();

    $hold->createdBy?->update(['tenant_id' => $otherTenant->id]);
})->throws(QueryException::class);

test('rejects cross-tenant activity attachment and concurrent active duplicates', function (string $failure): void {
    $tenant = TenantKey::factory()->create();
    $otherTenant = TenantKey::factory()->create();
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);

    if ($failure === 'cross tenant') {
        $activity = Activity::factory()->create(['tenant_id' => $otherTenant->id]);
    } else {
        LegalHoldActivityAttachment::factory()->create([
            'tenant_id' => $tenant->id,
            'legal_hold_id' => $hold->id,
            'activity_id' => $activity->id,
        ]);
    }

    LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_hold_id' => $hold->id,
        'activity_id' => $activity->id,
    ]);
})->with(['cross tenant', 'duplicate active'])->throws(QueryException::class);

test('detachment appends attribution and allows a new historical attachment', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->create();
    $detacher = User::factory()->create(['tenant_id' => $attachment->tenant_id]);
    $originalId = $attachment->id;

    $attachment->update([
        'detached_at' => now(),
        'detached_by_user_id' => $detacher->id,
        'detached_by_identity_id' => $detacher->id,
        'detachment_justification' => 'Evidence is no longer within the case scope.',
    ]);

    $reattached = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $attachment->tenant_id,
        'legal_hold_id' => $attachment->legal_hold_id,
        'activity_id' => $attachment->activity_id,
    ]);

    expect($attachment->fresh()?->id)->toBe($originalId)
        ->and($attachment->fresh()?->detached_by_identity_id)->toBe($detacher->id)
        ->and($attachment->fresh()?->detachment_justification)->not->toBeEmpty()
        ->and($reattached->id)->not->toBe($originalId)
        ->and(LegalHoldActivityAttachment::query()->count())->toBe(2);
});

test('prevents attachment evidence from being rewritten after detachment', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create();

    $attachment->update(['detachment_justification' => 'Replacement justification']);
})->throws(QueryException::class);

test('released holds retain all attachment history', function (): void {
    $hold = LegalHold::factory()->active()->create();
    $attached = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $hold->tenant_id,
        'legal_hold_id' => $hold->id,
    ]);
    $detached = LegalHoldActivityAttachment::factory()->detached()->create([
        'tenant_id' => $hold->tenant_id,
        'legal_hold_id' => $hold->id,
    ]);

    $releaser = User::factory()->create(['tenant_id' => $hold->tenant_id]);
    $hold->update([
        'status' => LegalHoldStatus::Released,
        'released_at' => now(),
        'released_by_user_id' => $releaser->id,
        'released_by_identity_id' => $releaser->id,
        'release_justification' => 'The proceeding has concluded.',
    ]);

    expect($hold->attachments)->toHaveCount(2)
        ->and($hold->attachments->pluck('id')->all())->toContain($attached->id, $detached->id);
});

test('released holds reject new attachment and detachment history', function (string $operation, string $message): void {
    $attachment = LegalHoldActivityAttachment::factory()->create();
    $hold = $attachment->legalHold;
    $releaser = User::factory()->create(['tenant_id' => $hold->tenant_id]);

    $hold->update([
        'status' => LegalHoldStatus::Released,
        'released_at' => now(),
        'released_by_user_id' => $releaser->id,
        'released_by_identity_id' => $releaser->id,
        'release_justification' => 'The proceeding has concluded.',
    ]);

    expect(function () use ($operation, $hold, $attachment): void {
        if ($operation === 'attach') {
            LegalHoldActivityAttachment::factory()->create([
                'tenant_id' => $hold->tenant_id,
                'legal_hold_id' => $hold->id,
            ]);

            return;
        }

        $detacher = User::factory()->create(['tenant_id' => $hold->tenant_id]);
        $attachment->update([
            'detached_at' => now(),
            'detached_by_user_id' => $detacher->id,
            'detached_by_identity_id' => $detacher->id,
            'detachment_justification' => 'Evidence is no longer within the case scope.',
        ]);
    })->toThrow(QueryException::class, $message);
})->with([
    'attach' => ['attach', 'attachments require an active legal hold'],
    'detach' => ['detach', 'detachments require an active legal hold'],
]);

test('released lifecycle and detached activity identities remain immutable history', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create();
    $activityIdentity = $attachment->activity_identity_id;
    $hold = $attachment->legalHold;
    $releaser = User::factory()->create(['tenant_id' => $hold->tenant_id]);

    $hold->update([
        'status' => LegalHoldStatus::Released,
        'released_at' => now(),
        'released_by_user_id' => $releaser->id,
        'released_by_identity_id' => $releaser->id,
        'release_justification' => 'The proceeding has concluded.',
    ]);
    $attachment->activity?->delete();

    expect($attachment->fresh()?->activity_id)->toBeNull()
        ->and($attachment->fresh()?->activity_identity_id)->toBe($activityIdentity);

    $hold->update([
        'status' => LegalHoldStatus::Active,
        'released_at' => null,
        'released_by_user_id' => null,
        'released_by_identity_id' => null,
        'release_justification' => null,
    ]);
})->throws(QueryException::class);

test('actor deletion retains opaque attachment and detachment attribution', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create();
    $attacherIdentity = $attachment->attached_by_identity_id;
    $detacherIdentity = $attachment->detached_by_identity_id;

    $attachment->attachedBy?->delete();
    $attachment->detachedBy?->delete();

    expect($attachment->fresh()?->attached_by_user_id)->toBeNull()
        ->and($attachment->fresh()?->attached_by_identity_id)->toBe($attacherIdentity)
        ->and($attachment->fresh()?->detached_by_user_id)->toBeNull()
        ->and($attachment->fresh()?->detached_by_identity_id)->toBe($detacherIdentity);
});

test('actor deletion retains opaque case creation and release attribution', function (): void {
    $hold = LegalHold::factory()->released()->create();
    $creatorIdentity = $hold->created_by_identity_id;
    $releaserIdentity = $hold->released_by_identity_id;

    $hold->createdBy?->delete();
    $hold->releasedBy?->delete();

    expect($hold->fresh()?->created_by_user_id)->toBeNull()
        ->and($hold->fresh()?->created_by_identity_id)->toBe($creatorIdentity)
        ->and($hold->fresh()?->released_by_user_id)->toBeNull()
        ->and($hold->fresh()?->released_by_identity_id)->toBe($releaserIdentity);
});

test('ordinary model behavior cannot physically delete hold or attachment evidence', function (string $model): void {
    $record = $model === 'hold'
        ? LegalHold::factory()->released()->create()
        : LegalHoldActivityAttachment::factory()->detached()->create();

    $record->delete();
})->with(['hold', 'attachment'])->throws(LogicException::class);

test('bulk model queries cannot bypass attachment history deletion protection', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create();

    LegalHoldActivityAttachment::query()->whereKey($attachment->id)->delete();
})->throws(QueryException::class);

test('truncate cannot bypass evidence deletion protection', function (string $statement): void {
    LegalHoldActivityAttachment::factory()->create();

    DB::statement($statement);
})->with([
    'attachments' => 'TRUNCATE legal_hold_activity_attachments',
    'holds with attachments' => 'TRUNCATE legal_holds, legal_hold_activity_attachments',
])->throws(QueryException::class);

test('tenant erasure follows the repository cascade contract', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create();
    $tenant = $attachment->tenant;
    $holdId = $attachment->legal_hold_id;
    $attachmentId = $attachment->id;

    $tenant->delete();

    expect(LegalHold::query()->whereKey($holdId)->exists())->toBeFalse()
        ->and(LegalHoldActivityAttachment::query()->whereKey($attachmentId)->exists())->toBeFalse();
});

test('models relationships and factories remain tenant consistent', function (): void {
    $attachment = LegalHoldActivityAttachment::factory()->detached()->create();
    $hold = $attachment->legalHold;

    expect($hold->status)->toBe(LegalHoldStatus::Active)
        ->and($attachment->tenant->is($hold->tenant))->toBeTrue()
        ->and($attachment->activity->tenant_id)->toBe($hold->tenant_id)
        ->and($attachment->attachedBy?->tenant_id)->toBe($hold->tenant_id)
        ->and($attachment->detachedBy?->tenant_id)->toBe($hold->tenant_id)
        ->and($hold->attachments->contains($attachment))->toBeTrue()
        ->and($hold->tenant->legalHolds->contains($hold))->toBeTrue()
        ->and($attachment->activity->legalHoldAttachments->contains($attachment))->toBeTrue();

    $released = LegalHold::factory()->released()->create();

    expect($released->status)->toBe(LegalHoldStatus::Released)
        ->and($released->released_at)->not->toBeNull()
        ->and($released->created_at?->equalTo($released->released_at))->toBeTrue()
        ->and($released->released_by_identity_id)->not->toBeNull();
});

test('the migration rolls back and reapplies cleanly', function (): void {
    $migration = require database_path('migrations/2026_09_09_130000_create_legal_hold_persistence.php');
    $migration->down();

    expect(Schema::hasTable('legal_hold_activity_attachments'))->toBeFalse()
        ->and(Schema::hasTable('legal_holds'))->toBeFalse()
        ->and(DB::table('pg_proc')
            ->whereRaw('pronamespace = current_schema()::regnamespace')
            ->whereIn('proname', [
                'enforce_legal_hold_attachment_history',
                'enforce_legal_hold_evidence_deletion',
                'enforce_legal_hold_history',
                'enforce_legal_hold_is_active',
            ])
            ->exists())->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('legal_holds'))->toBeTrue()
        ->and(Schema::hasTable('legal_hold_activity_attachments'))->toBeTrue();
});

test('the activity retention protection migration rolls back and reapplies cleanly', function (): void {
    $migration = require database_path('migrations/2026_09_09_140000_protect_held_activity_from_deletion.php');
    $migration->down();

    expect(DB::table('pg_proc')
        ->whereRaw('pronamespace = current_schema()::regnamespace')
        ->whereIn('proname', [
            'activity_is_actively_held',
            'prevent_actively_held_activity_deletion',
        ])
        ->exists())->toBeFalse()
        ->and(DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', 'legal_hold_attachments_active_activity_identity_index')
            ->exists())->toBeFalse();

    $migration->up();

    expect(DB::table('pg_proc')
        ->whereRaw('pronamespace = current_schema()::regnamespace')
        ->where('proname', 'activity_is_actively_held')
        ->exists())->toBeTrue()
        ->and(DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', 'legal_hold_attachments_active_activity_identity_index')
            ->exists())->toBeTrue();
});
