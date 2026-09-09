<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\LegalHoldAuditOperation;
use App\Enums\LegalHoldAuditOutcome;
use App\Enums\LegalHoldStatus;
use App\Exceptions\DuplicateActiveLegalHoldAttachmentException;
use App\Exceptions\LegalHoldAuditFailureException;
use App\Exceptions\LegalHoldCaseReferenceConflictException;
use App\Exceptions\LegalHoldNestedTransactionException;
use App\Exceptions\LegalHoldNotActiveException;
use App\Exceptions\LegalHoldTargetNotFoundException;
use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\TenantKey;
use App\Models\User;
use App\Repositories\LegalHoldRepository;
use App\Services\LegalHoldAuditRecorder;
use App\Services\LegalHoldService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Artisan::call('migrate:fresh', ['--force' => true]);
    DB::unprepared('DROP FUNCTION IF EXISTS fail_legal_hold_audit_hash()');
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function legalHoldAuditActor(TenantKey $tenant): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['read', 'create', 'attach', 'detach', 'release'] as $ability) {
        givePermissionWithTenant($actor, $tenant->id, "legal_holds.{$ability}");
    }
    givePermissionWithTenant($actor, $tenant->id, 'activity_log.read');
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $actor;
}

test('authoritative lifecycle outcomes create bounded Activity audit evidence', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    $actor->forceFill([
        'name' => 'ACTOR-NAME-SECRET',
        'email' => 'actor-secret@example.test',
    ])->save();
    app()->instance('request', Request::create(
        '/legal-holds',
        'POST',
        ['request_body' => 'REQUEST-BODY-MARKER-SECRET'],
        server: ['HTTP_AUTHORIZATION' => 'Bearer TOKEN-MARKER-SECRET'],
    ));
    $service = app(LegalHoldService::class);
    $startedAt = now();

    $hold = $service->create($actor, 'CASE-AUDIT-454', 'CREATE-JUSTIFICATION-SECRET');
    $evidence = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'properties' => ['payload' => 'ACTIVITY-PAYLOAD-SECRET'],
    ]);
    $attachment = $service->attach($actor, $hold->id, $evidence->id);

    expect(fn () => $service->attach($actor, $hold->id, $evidence->id))
        ->toThrow(DuplicateActiveLegalHoldAttachmentException::class);

    $service->detach($actor, $hold->id, $attachment->id, 'DETACH-JUSTIFICATION-SECRET');
    $service->release($actor, $hold->id, 'RELEASE-JUSTIFICATION-SECRET');

    $audits = Activity::query()
        ->where('tenant_id', $tenant->id)
        ->where('event', 'like', 'legal_hold.%')
        ->orderBy('id')
        ->get();

    expect($audits->pluck('event')->all())->toBe([
        'legal_hold.create.succeeded',
        'legal_hold.attach.succeeded',
        'legal_hold.attach.failed',
        'legal_hold.detach.succeeded',
        'legal_hold.release.succeeded',
    ]);

    $expectedReasons = [
        'legal_hold.create.succeeded' => 'completed',
        'legal_hold.attach.succeeded' => 'completed',
        'legal_hold.attach.failed' => 'duplicate_active_attachment',
        'legal_hold.detach.succeeded' => 'completed',
        'legal_hold.release.succeeded' => 'completed',
    ];
    $allowedKeys = [
        'schema_version',
        'operation',
        'outcome',
        'case_reference',
        'reason_category',
        'legal_hold_id',
        'attachment_id',
        'activity_identity_id',
    ];

    foreach ($audits as $audit) {
        $properties = $audit->properties->all();

        expect($audit->tenant_id)->toBe($tenant->id)
            ->and($audit->log_name)->toBe('security')
            ->and($audit->causer_type)->toBe(User::class)
            ->and($audit->causer_id)->toBe($actor->id)
            ->and($audit->subject_type)->toBeNull()
            ->and($audit->subject_id)->toBeNull()
            ->and($audit->created_at->greaterThanOrEqualTo($startedAt->copy()->subSecond()))->toBeTrue()
            ->and($audit->description)->toBe(
                str_ends_with((string) $audit->event, '.succeeded')
                    ? 'Legal Hold lifecycle mutation succeeded'
                    : 'Legal Hold lifecycle mutation failed',
            )
            ->and($properties['schema_version'])->toBe(1)
            ->and($properties['operation'])->toBe(explode('.', (string) $audit->event)[1])
            ->and($properties['outcome'])->toBe(explode('.', (string) $audit->event)[2])
            ->and($properties['case_reference'])->toBe('CASE-AUDIT-454')
            ->and($properties['reason_category'])->toBe($expectedReasons[$audit->event])
            ->and(array_diff(array_keys($properties), $allowedKeys))->toBe([])
            ->and($audit->event_hash)->not->toBeNull()
            ->and($audit->verifyChain())->toBeTrue();
    }

    expect($audits->firstWhere('event', 'legal_hold.create.succeeded')?->properties->all())
        ->toMatchArray(['legal_hold_id' => $hold->id])
        ->and($audits->firstWhere('event', 'legal_hold.attach.succeeded')?->properties->all())
        ->toMatchArray([
            'legal_hold_id' => $hold->id,
            'attachment_id' => $attachment->id,
            'activity_identity_id' => $evidence->id,
        ])
        ->and($audits->firstWhere('event', 'legal_hold.attach.failed')?->properties->all())
        ->toMatchArray([
            'legal_hold_id' => $hold->id,
            'activity_identity_id' => $evidence->id,
        ]);

    $persistedAudit = json_encode(
        DB::table('activity_log')
            ->whereIn('id', $audits->pluck('id'))
            ->orderBy('id')
            ->get()
            ->all(),
        JSON_THROW_ON_ERROR,
    );

    foreach ([
        'CREATE-JUSTIFICATION-SECRET',
        'DETACH-JUSTIFICATION-SECRET',
        'RELEASE-JUSTIFICATION-SECRET',
        'ACTIVITY-PAYLOAD-SECRET',
        'TOKEN-MARKER-SECRET',
        'REQUEST-BODY-MARKER-SECRET',
        'actor-secret@example.test',
        'ACTOR-NAME-SECRET',
    ] as $forbiddenValue) {
        expect($persistedAudit)->not->toContain($forbiddenValue);
    }

    expect(Activity::getRetentionYearsForLogType('security'))->toBe(3)
        ->and(Activity::getAllRetentionYears())->toHaveKey('security');
});

test('case conflicts and inactive holds record bounded failed outcomes after rollback', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    $service = app(LegalHoldService::class);
    $hold = $service->create($actor, 'CASE-FAILED-AUDIT', 'Original hold.');

    expect(fn () => $service->create($actor, 'CASE-FAILED-AUDIT', 'Duplicate hold.'))
        ->toThrow(LegalHoldCaseReferenceConflictException::class);

    $service->release($actor, $hold->id, 'Initial release.');

    expect(fn () => $service->release($actor, $hold->id, 'Second release.'))
        ->toThrow(LegalHoldNotActiveException::class);

    $failedAudits = Activity::query()
        ->where('tenant_id', $tenant->id)
        ->whereIn('event', ['legal_hold.create.failed', 'legal_hold.release.failed'])
        ->orderBy('id')
        ->get();

    expect($failedAudits)->toHaveCount(2)
        ->and($failedAudits[0]->properties->all())->toMatchArray([
            'operation' => 'create',
            'outcome' => 'failed',
            'case_reference' => 'CASE-FAILED-AUDIT',
            'reason_category' => 'case_reference_conflict',
        ])
        ->and($failedAudits[1]->properties->all())->toMatchArray([
            'operation' => 'release',
            'outcome' => 'failed',
            'case_reference' => 'CASE-FAILED-AUDIT',
            'reason_category' => 'hold_not_active',
            'legal_hold_id' => $hold->id,
        ])
        ->and(LegalHold::query()->where('case_reference', 'CASE-FAILED-AUDIT')->count())->toBe(1)
        ->and($hold->fresh()?->release_justification)->toBe('Initial release.');
});

test('expected domain and persistence failures use only closed reason categories', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    $service = app(LegalHoldService::class);

    expect(fn () => $service->create($actor, '   ', 'Invalid case reference.'))
        ->toThrow(InvalidArgumentException::class);

    $hold = LegalHold::factory()->create([
        'tenant_id' => $tenant->id,
        'case_reference' => 'CASE-REASON-CATEGORIES',
    ]);

    expect(fn () => $service->detach(
        $actor,
        $hold->id,
        (string) Str::uuid(),
        'Unavailable target.',
    ))->toThrow(LegalHoldTargetNotFoundException::class);

    $attachment = LegalHoldActivityAttachment::factory()
        ->detached()
        ->create([
            'tenant_id' => $tenant->id,
            'legal_hold_id' => $hold->id,
        ]);

    expect(fn () => $service->detach(
        $actor,
        $hold->id,
        $attachment->id,
        'Replacement reason.',
    ))->toThrow(App\Exceptions\LegalHoldAttachmentAlreadyDetachedException::class);

    $realRepository = new LegalHoldRepository;
    $repository = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $repository->shouldReceive('updateHold')
        ->once()
        ->andReturnUsing(function (LegalHold $record, array $attributes) use ($realRepository): never {
            $realRepository->updateHold($record, $attributes);

            throw new RuntimeException('SQL-DRIVER-MESSAGE-MUST-NOT-BE-AUDITED');
        });
    app()->instance(LegalHoldRepository::class, $repository);

    expect(fn () => app(LegalHoldService::class)->release(
        $actor,
        $hold->id,
        'Persistence rollback.',
    ))->toThrow(RuntimeException::class, 'SQL-DRIVER-MESSAGE-MUST-NOT-BE-AUDITED');

    $failedAudits = Activity::query()
        ->where('event', 'like', 'legal_hold.%.failed')
        ->orderBy('id')
        ->get();

    expect($failedAudits->pluck('properties.reason_category')->all())->toBe([
        'invalid_input',
        'target_unavailable',
        'attachment_already_detached',
        'persistence_failure',
    ])
        ->and($failedAudits[0]->properties->get('case_reference'))->toBeNull()
        ->and($failedAudits[1]->properties->get('case_reference'))->toBe('CASE-REASON-CATEGORIES')
        ->and($failedAudits[1]->properties->has('attachment_id'))->toBeFalse()
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Active)
        ->and(json_encode(
            DB::table('activity_log')->whereIn('id', $failedAudits->pluck('id'))->get()->all(),
            JSON_THROW_ON_ERROR,
        ))->not->toContain('SQL-DRIVER-MESSAGE-MUST-NOT-BE-AUDITED');
});

test('audit failure rolls back every successful mutation family', function (string $operation): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $evidence = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $attachment = LegalHoldActivityAttachment::factory()->create([
        'tenant_id' => $tenant->id,
        'legal_hold_id' => $hold->id,
        'activity_id' => $evidence->id,
        'activity_identity_id' => $evidence->id,
    ]);
    $audits = Mockery::mock(LegalHoldAuditRecorder::class);
    $audits->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('INJECTED-AUDIT-FAILURE'));
    app()->instance(LegalHoldAuditRecorder::class, $audits);
    $service = app(LegalHoldService::class);

    $call = match ($operation) {
        'create' => fn () => $service->create($actor, 'CASE-AUDIT-ROLLBACK', 'Must roll back.'),
        'attach' => fn () => $service->attach(
            $actor,
            $hold->id,
            Activity::factory()->create(['tenant_id' => $tenant->id])->id,
        ),
        'detach' => fn () => $service->detach($actor, $hold->id, $attachment->id, 'Must roll back.'),
        'release' => fn () => $service->release($actor, $hold->id, 'Must roll back.'),
    };

    expect($call)->toThrow(LegalHoldAuditFailureException::class)
        ->and(LegalHold::query()->where('case_reference', 'CASE-AUDIT-ROLLBACK')->exists())->toBeFalse()
        ->and(LegalHoldActivityAttachment::query()
            ->where('legal_hold_id', $hold->id)
            ->where('activity_identity_id', '!=', $evidence->id)
            ->exists())->toBeFalse()
        ->and($attachment->fresh()?->detached_at)->toBeNull()
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Active)
        ->and(Activity::query()->where('event', 'like', 'legal_hold.%')->exists())->toBeFalse();
})->with(['create', 'attach', 'detach', 'release']);

test('synchronous Activity hash-chain failure rolls back the mutation and unhashed audit row', function (): void {
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fail_legal_hold_audit_hash()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            IF OLD.event LIKE 'legal_hold.%' THEN
                RAISE EXCEPTION 'injected Legal Hold hash-chain failure'
                    USING ERRCODE = '23514';
            END IF;

            RETURN NEW;
        END;
        $$;

        CREATE TRIGGER fail_legal_hold_audit_hash
        BEFORE UPDATE OF event_hash ON activity_log
        FOR EACH ROW
        EXECUTE FUNCTION fail_legal_hold_audit_hash();
        SQL);

    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);

    expect(fn () => app(LegalHoldService::class)->create(
        $actor,
        'CASE-HASH-FAILURE',
        'Must roll back with the audit row.',
    ))->toThrow(LegalHoldAuditFailureException::class)
        ->and(LegalHold::query()->where('case_reference', 'CASE-HASH-FAILURE')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'legal_hold.create.succeeded')->exists())->toBeFalse();
});

test('failed mutation audit failure stays rolled back and surfaces causal evidence once', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $evidence = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $realRepository = new LegalHoldRepository;
    $repository = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $repository->shouldReceive('attach')
        ->once()
        ->andReturnUsing(function (array $attributes) use ($realRepository): never {
            $realRepository->attach($attributes);

            throw new RuntimeException('INJECTED-MUTATION-FAILURE');
        });
    app()->instance(LegalHoldRepository::class, $repository);
    $audits = Mockery::mock(LegalHoldAuditRecorder::class);
    $audits->shouldReceive('record')
        ->once()
        ->withArgs(fn ($context, $outcome): bool => $outcome === LegalHoldAuditOutcome::Failed)
        ->andThrow(new RuntimeException('INJECTED-FAILED-AUDIT-FAILURE'));
    app()->instance(LegalHoldAuditRecorder::class, $audits);

    try {
        app(LegalHoldService::class)->attach($actor, $hold->id, $evidence->id);
        test()->fail('Expected the failed-audit exception.');
    } catch (LegalHoldAuditFailureException $exception) {
        expect($exception->operation)->toBe(LegalHoldAuditOperation::Attach)
            ->and($exception->outcome)->toBe(LegalHoldAuditOutcome::Failed)
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('INJECTED-MUTATION-FAILURE')
            ->and($exception->auditFailure->getMessage())->toBe('INJECTED-FAILED-AUDIT-FAILURE');
    }

    expect(LegalHoldActivityAttachment::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'like', 'legal_hold.%')->exists())->toBeFalse();
});

test('request organizational unit input cannot scope or break lifecycle audits', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    app()->instance('request', Request::create(
        '/legal-holds',
        'POST',
        ['organizational_unit_id' => (string) Str::uuid()],
    ));

    $hold = app(LegalHoldService::class)->create(
        $actor,
        'CASE-UNTRUSTED-OU',
        'The request must not control audit scope.',
    );
    $audit = Activity::query()->where('event', 'legal_hold.create.succeeded')->sole();

    expect($hold->case_reference)->toBe('CASE-UNTRUSTED-OU')
        ->and($audit->organizational_unit_id)->toBeNull();
});

test('pre-resolution database failures record neutral persistence evidence', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);
    $repository = Mockery::mock(LegalHoldRepository::class)->makePartial();
    $failure = new Illuminate\Database\QueryException(
        'pgsql',
        'select * from legal_holds',
        [],
        new PDOException('PRE-RESOLUTION-DRIVER-SECRET'),
    );
    $repository->shouldReceive('lock')->once()->andThrow($failure);
    app()->instance(LegalHoldRepository::class, $repository);

    expect(fn () => app(LegalHoldService::class)->release(
        $actor,
        (string) Str::uuid(),
        'Unavailable before resolution.',
    ))->toThrow(Illuminate\Database\QueryException::class);

    $audit = Activity::query()->where('event', 'legal_hold.release.failed')->sole();

    expect($audit->properties->all())->toBe([
        'schema_version' => 1,
        'operation' => 'release',
        'outcome' => 'failed',
        'case_reference' => null,
        'reason_category' => 'persistence_failure',
    ])->and(json_encode(
        DB::table('activity_log')->where('id', $audit->id)->first(),
        JSON_THROW_ON_ERROR,
    ))->not->toContain('PRE-RESOLUTION-DRIVER-SECRET');
});

test('lifecycle mutations reject caller-owned transactions before mutation', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldAuditActor($tenant);

    expect(fn () => DB::transaction(fn () => app(LegalHoldService::class)->create(
        $actor,
        'CASE-NESTED-TRANSACTION',
        'Must never depend on a caller transaction.',
    )))->toThrow(LegalHoldNestedTransactionException::class)
        ->and(LegalHold::query()->where('case_reference', 'CASE-NESTED-TRANSACTION')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'like', 'legal_hold.%')->exists())->toBeFalse();
});

test('unauthorized actors are not audited and unresolved foreign targets use neutral evidence', function (): void {
    $tenant = TenantKey::factory()->create();
    $foreignTenant = TenantKey::factory()->create();
    $unauthorized = User::factory()->create(['tenant_id' => $tenant->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    expect(fn () => app(LegalHoldService::class)->create(
        $unauthorized,
        'CASE-NOT-AUTHORIZED',
        'Must not be attributed.',
    ))->toThrow(AuthorizationException::class);

    $actor = legalHoldAuditActor($tenant);
    $foreignHold = LegalHold::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'case_reference' => 'FOREIGN-CASE-REFERENCE-SECRET',
    ]);

    expect(fn () => app(LegalHoldService::class)->release(
        $actor,
        $foreignHold->id,
        'Must not disclose the target.',
    ))->toThrow(LegalHoldTargetNotFoundException::class);

    $audit = Activity::query()->where('event', 'legal_hold.release.failed')->sole();

    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->causer_id)->toBe($actor->id)
        ->and($audit->properties->all())->toBe([
            'schema_version' => 1,
            'operation' => 'release',
            'outcome' => 'failed',
            'case_reference' => null,
            'reason_category' => 'target_unavailable',
        ]);

    $persistedActivities = json_encode(
        DB::table('activity_log')->get()->all(),
        JSON_THROW_ON_ERROR,
    );

    expect($persistedActivities)->not->toContain('FOREIGN-CASE-REFERENCE-SECRET');
});
