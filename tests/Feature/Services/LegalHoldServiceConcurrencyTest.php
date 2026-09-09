<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\LegalHoldStatus;
use App\Exceptions\DuplicateActiveLegalHoldAttachmentException;
use App\Exceptions\LegalHoldNotActiveException;
use App\Models\Activity;
use App\Models\ActivityArchive;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\LegalHoldService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function ensureLegalHoldConcurrencyDatabase(): void
{
    $token = ParallelTesting::token();

    if ($token !== false && $token !== '') {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $suffix = '_test_'.$token;

        if (! str_ends_with($database, $suffix)) {
            $rootDatabase = preg_replace('/_test_\d+$/', '', $database);

            if (is_string($rootDatabase) && $rootDatabase !== '') {
                config()->set("database.connections.{$connection}.database", $rootDatabase.$suffix);
                config()->set("database.connections.{$connection}.url", null);
            }
        }
    }

    DB::purge();
    DB::reconnect();
}

function refreshLegalHoldConcurrencyDatabase(): void
{
    ensureLegalHoldConcurrencyDatabase();
    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

beforeEach(function (): void {
    refreshLegalHoldConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshLegalHoldConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function legalHoldConcurrencyActor(TenantKey $tenant): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    givePermissionWithTenant($actor, $tenant->id, 'activity_log.read');
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $actor;
}

/**
 * @param  Closure(int, User, LegalHoldService): void  $operation
 * @return Illuminate\Support\Collection<int, array{status: string, exception: string|null}>
 */
function runConcurrentLegalHoldOperations(
    User $actor,
    int $workerCount,
    Closure $operation,
): Illuminate\Support\Collection {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for legal hold concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/legal-hold-concurrency-'.uniqid('', true);

    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create legal hold concurrency directory.');
    }

    $signal = $directory.'/start.signal';
    file_put_contents($signal, 'wait');
    DB::disconnect();

    try {
        $pids = [];

        for ($worker = 1; $worker <= $workerCount; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork legal hold concurrency worker.');
            }

            if ($pid === 0) {
                if (function_exists('xdebug_stop_code_coverage')) {
                    xdebug_stop_code_coverage(false);
                }

                DB::purge();
                DB::reconnect();
                $registrar = app(PermissionRegistrar::class);
                $registrar->forgetCachedPermissions();
                $registrar->setPermissionsTeamId($actor->tenant_id);

                while (trim((string) file_get_contents($signal)) !== 'go') {
                    usleep(10_000);
                }

                try {
                    $operation($worker, User::query()->findOrFail($actor->id), app(LegalHoldService::class));
                    $result = ['status' => 'success', 'exception' => null];
                } catch (Throwable $exception) {
                    $result = ['status' => 'failure', 'exception' => $exception::class];
                }

                file_put_contents(
                    $directory."/result-{$worker}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                );
                exit(0);
            }

            $pids[] = $pid;
        }

        file_put_contents($signal, 'go');

        foreach ($pids as $pid) {
            expect(pcntl_waitpid($pid, $status))->toBe($pid)
                ->and(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wifsignaled($status))->toBeFalse()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }

        DB::reconnect();

        return collect(range(1, $workerCount))->map(function (int $worker) use ($directory): array {
            /** @var array{status: string, exception: string|null} $result */
            $result = json_decode(
                (string) file_get_contents($directory."/result-{$worker}.json"),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return $result;
        });
    } finally {
        DB::reconnect();

        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
}

test('concurrent duplicate attachment commits one active evidence row', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        fn (int $worker, User $currentActor, LegalHoldService $service) => $service->attach(
            $currentActor,
            $hold->id,
            $activity->id,
        ),
    );

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', DuplicateActiveLegalHoldAttachmentException::class))->toHaveCount(1)
        ->and(LegalHoldActivityAttachment::query()->whereNull('detached_at')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'legal_hold.attach.succeeded')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'legal_hold.attach.failed')->count())->toBe(1);

    $successAudit = Activity::query()->where('event', 'legal_hold.attach.succeeded')->firstOrFail();
    $failureAudit = Activity::query()->where('event', 'legal_hold.attach.failed')->firstOrFail();

    expect($successAudit->id)->toBeLessThan($failureAudit->id)
        ->and($failureAudit->properties->get('reason_category'))->toBe('duplicate_active_attachment');
});

test('concurrent release and attachment are serially safe', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        function (int $worker, User $currentActor, LegalHoldService $service) use ($hold, $activity): void {
            if ($worker === 1) {
                $service->release($currentActor, $hold->id, 'Concurrent release.');
            } else {
                $service->attach($currentActor, $hold->id, $activity->id);
            }
        },
    );

    $hold->refresh();
    $attachment = LegalHoldActivityAttachment::query()->first();

    expect($hold->status)->toBe(LegalHoldStatus::Released)
        ->and($results->where('status', 'success')->count())->toBeIn([1, 2])
        ->and($results->where('exception', LegalHoldNotActiveException::class)->count())->toBeIn([0, 1])
        ->and($results->where('status', 'success')->count()
            + $results->where('exception', LegalHoldNotActiveException::class)->count())->toBe(2)
        ->and(LegalHoldActivityAttachment::query()->count())->toBeIn([0, 1]);

    if ($attachment !== null) {
        expect($attachment->attached_at->lessThanOrEqualTo($hold->released_at))->toBeTrue()
            ->and(Activity::query()->where('event', 'legal_hold.attach.succeeded')->firstOrFail()->id)
            ->toBeLessThan(Activity::query()->where('event', 'legal_hold.release.succeeded')->firstOrFail()->id);
    } else {
        expect(Activity::query()->where('event', 'legal_hold.release.succeeded')->firstOrFail()->id)
            ->toBeLessThan(Activity::query()->where('event', 'legal_hold.attach.failed')->firstOrFail()->id)
            ->and(Activity::query()
                ->where('event', 'legal_hold.attach.failed')
                ->firstOrFail()
                ->properties
                ->get('reason_category'))->toBe('hold_not_active');
    }
});

test('concurrent release and detachment are serially safe', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create(['tenant_id' => $tenant->id]);
    $attachment = app(LegalHoldService::class)->attach($actor, $hold->id, $activity->id);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        function (int $worker, User $currentActor, LegalHoldService $service) use ($hold, $attachment): void {
            if ($worker === 1) {
                $service->release($currentActor, $hold->id, 'Concurrent release.');
            } else {
                $service->detach($currentActor, $hold->id, $attachment->id, 'Concurrent detachment.');
            }
        },
    );

    $hold->refresh();
    $attachment->refresh();

    expect($hold->status)->toBe(LegalHoldStatus::Released)
        ->and($results->where('status', 'success')->count())->toBeIn([1, 2])
        ->and($results->where('exception', LegalHoldNotActiveException::class)->count())->toBeIn([0, 1])
        ->and($results->where('status', 'success')->count()
            + $results->where('exception', LegalHoldNotActiveException::class)->count())->toBe(2);

    if ($attachment->detached_at !== null) {
        expect($attachment->detached_at->lessThanOrEqualTo($hold->released_at))->toBeTrue()
            ->and(Activity::query()->where('event', 'legal_hold.detach.succeeded')->firstOrFail()->id)
            ->toBeLessThan(Activity::query()->where('event', 'legal_hold.release.succeeded')->firstOrFail()->id);
    } else {
        expect(Activity::query()->where('event', 'legal_hold.release.succeeded')->firstOrFail()->id)
            ->toBeLessThan(Activity::query()->where('event', 'legal_hold.detach.failed')->firstOrFail()->id)
            ->and(Activity::query()
                ->where('event', 'legal_hold.detach.failed')
                ->firstOrFail()
                ->properties
                ->get('reason_category'))->toBe('hold_not_active');
    }
});

test('concurrent releases commit exactly one lifecycle transition', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        fn (int $worker, User $currentActor, LegalHoldService $service) => $service->release(
            $currentActor,
            $hold->id,
            "Concurrent release {$worker}.",
        ),
    );

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', LegalHoldNotActiveException::class))->toHaveCount(1)
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Released)
        ->and(Activity::query()->where('event', 'legal_hold.release.succeeded')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'legal_hold.release.failed')->count())->toBe(1);

    $successAudit = Activity::query()->where('event', 'legal_hold.release.succeeded')->firstOrFail();
    $failureAudit = Activity::query()->where('event', 'legal_hold.release.failed')->firstOrFail();

    expect($successAudit->id)->toBeLessThan($failureAudit->id)
        ->and($failureAudit->properties->get('reason_category'))->toBe('hold_not_active');
});

test('attachment auditing cannot deadlock with concurrent Activity hashing', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $evidence = Activity::factory()->create(['tenant_id' => $tenant->id]);

    DB::unprepared(<<<SQL
        CREATE FUNCTION delay_legal_hold_attachment_454()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            IF NEW.legal_hold_id = '{$hold->id}'::uuid THEN
                PERFORM pg_sleep(2);
            END IF;

            RETURN NEW;
        END;
        $$;

        CREATE TRIGGER delay_legal_hold_attachment_454
        AFTER INSERT ON legal_hold_activity_attachments
        FOR EACH ROW
        EXECUTE FUNCTION delay_legal_hold_attachment_454();
        SQL);

    try {
        $results = runConcurrentLegalHoldOperations(
            $actor,
            2,
            function (int $worker, User $currentActor, LegalHoldService $service) use ($hold, $evidence, $tenant): void {
                if ($worker === 1) {
                    $service->attach($currentActor, $hold->id, $evidence->id);

                    return;
                }

                usleep(200_000);
                Activity::create([
                    'tenant_id' => $tenant->id,
                    'log_name' => 'security',
                    'description' => 'Concurrent forensic evidence',
                    'event' => 'concurrent.forensic.evidence',
                ]);
            },
        );
    } finally {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS delay_legal_hold_attachment_454
                ON legal_hold_activity_attachments;
            DROP FUNCTION IF EXISTS delay_legal_hold_attachment_454();
            SQL);
    }

    $activities = Activity::query()
        ->where('tenant_id', $tenant->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($results->where('status', 'success'))->toHaveCount(2)
        ->and(Activity::query()->where('event', 'legal_hold.attach.succeeded')->count())->toBe(1)
        ->and($activities->every(fn (Activity $activity): bool => $activity->verifyChain()))->toBeTrue();
});

test('concurrent lifecycle audits preserve canonical Activity hash order', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $slowHold = LegalHold::factory()->create([
        'tenant_id' => $tenant->id,
        'case_reference' => 'CASE-SLOW-AUDIT-454',
    ]);
    $fastHold = LegalHold::factory()->create([
        'tenant_id' => $tenant->id,
        'case_reference' => 'CASE-FAST-AUDIT-454',
    ]);

    DB::unprepared(<<<'SQL'
        CREATE FUNCTION delay_legal_hold_audit_454()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            IF NEW.event = 'legal_hold.release.succeeded'
                AND NEW.properties->>'case_reference' = 'CASE-SLOW-AUDIT-454' THEN
                PERFORM pg_sleep(2);
            END IF;

            RETURN NEW;
        END;
        $$;

        CREATE TRIGGER delay_legal_hold_audit_454
        AFTER INSERT ON activity_log
        FOR EACH ROW
        EXECUTE FUNCTION delay_legal_hold_audit_454();
        SQL);

    try {
        $results = runConcurrentLegalHoldOperations(
            $actor,
            2,
            function (int $worker, User $currentActor, LegalHoldService $service) use ($slowHold, $fastHold): void {
                if ($worker === 1) {
                    $service->release($currentActor, $slowHold->id, 'Slow concurrent release.');

                    return;
                }

                usleep(200_000);
                $service->release($currentActor, $fastHold->id, 'Fast concurrent release.');
            },
        );
    } finally {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS delay_legal_hold_audit_454 ON activity_log;
            DROP FUNCTION IF EXISTS delay_legal_hold_audit_454();
            SQL);
    }

    $audits = Activity::query()
        ->where('event', 'legal_hold.release.succeeded')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($results->where('status', 'success'))->toHaveCount(2)
        ->and($audits)->toHaveCount(2)
        ->and($audits[1]->previous_hash)->toBe($audits[0]->event_hash)
        ->and($audits->every(fn (Activity $activity): bool => $activity->verifyChain()))->toBeTrue();
});

test('concurrent retention and attachment either preserve held evidence or reject the late attachment', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYears(4)->startOfYear(),
    ]);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        function (int $worker, User $currentActor, LegalHoldService $service) use ($hold, $activity, $tenant): void {
            if ($worker === 1) {
                $service->attach($currentActor, $hold->id, $activity->id);

                return;
            }

            if (Artisan::call('activity:apply-retention', ['--tenant' => $tenant->id]) !== 0) {
                throw new RuntimeException('Retention command failed.');
            }
        },
    );

    $activeAttachment = LegalHoldActivityAttachment::query()
        ->where('activity_identity_id', $activity->id)
        ->whereNull('detached_at')
        ->first();

    expect($results->where('status', 'success')->count())->toBeGreaterThanOrEqual(1);

    if ($activeAttachment !== null) {
        expect($activity->fresh())->not->toBeNull()
            ->and(ActivityArchive::query()->whereKey($activity->id)->exists())->toBeFalse();
    } else {
        expect($activity->fresh())->toBeNull()
            ->and(ActivityArchive::query()->whereKey($activity->id)->count())->toBe(1);
    }
});

test('concurrent detachment and retention preserve ordering and later eligibility', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYears(4)->startOfYear(),
    ]);
    $attachment = app(LegalHoldService::class)->attach($actor, $hold->id, $activity->id);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        function (int $worker, User $currentActor, LegalHoldService $service) use ($hold, $attachment, $tenant): void {
            if ($worker === 1) {
                $service->detach($currentActor, $hold->id, $attachment->id, 'Concurrent scope change.');

                return;
            }

            if (Artisan::call('activity:apply-retention', ['--tenant' => $tenant->id]) !== 0) {
                throw new RuntimeException('Retention command failed.');
            }
        },
    );

    expect($results->where('status', 'success'))->toHaveCount(2)
        ->and($attachment->fresh()?->detached_at)->not->toBeNull();

    expect(Artisan::call('activity:apply-retention', ['--tenant' => $tenant->id]))->toBe(0)
        ->and($activity->fresh())->toBeNull()
        ->and(ActivityArchive::query()->whereKey($activity->id)->count())->toBe(1);
});

test('concurrent release and retention preserve ordering and later eligibility', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $hold = LegalHold::factory()->create(['tenant_id' => $tenant->id]);
    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYears(4)->startOfYear(),
    ]);
    app(LegalHoldService::class)->attach($actor, $hold->id, $activity->id);

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        function (int $worker, User $currentActor, LegalHoldService $service) use ($hold, $tenant): void {
            if ($worker === 1) {
                $service->release($currentActor, $hold->id, 'Concurrent proceeding closure.');

                return;
            }

            if (Artisan::call('activity:apply-retention', ['--tenant' => $tenant->id]) !== 0) {
                throw new RuntimeException('Retention command failed.');
            }
        },
    );

    expect($results->where('status', 'success'))->toHaveCount(2)
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Released);

    expect(Artisan::call('activity:apply-retention', ['--tenant' => $tenant->id]))->toBe(0)
        ->and($activity->fresh())->toBeNull()
        ->and(ActivityArchive::query()->whereKey($activity->id)->count())->toBe(1);
});

test('concurrent retention workers archive and orphan each activity once', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = legalHoldConcurrencyActor($tenant);
    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subYears(4)->startOfYear(),
    ])->refresh();
    $successor = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
    ])->refresh();

    $results = runConcurrentLegalHoldOperations(
        $actor,
        2,
        function (int $worker, User $currentActor, LegalHoldService $service) use ($tenant): void {
            if (Artisan::call('activity:apply-retention', ['--tenant' => $tenant->id]) !== 0) {
                throw new RuntimeException('Retention command failed.');
            }
        },
    );

    $successor->refresh();

    expect($results->where('status', 'success'))->toHaveCount(2)
        ->and($activity->fresh())->toBeNull()
        ->and(ActivityArchive::query()->whereKey($activity->id)->count())->toBe(1)
        ->and($successor->is_orphaned_genesis)->toBeTrue()
        ->and($successor->previous_hash)->toBeNull()
        ->and($successor->verifyChain())->toBeTrue();
});
