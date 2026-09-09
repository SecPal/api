<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\LegalHoldStatus;
use App\Exceptions\DuplicateActiveLegalHoldAttachmentException;
use App\Exceptions\LegalHoldNotActiveException;
use App\Models\Activity;
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
        ->and(LegalHoldActivityAttachment::query()->whereNull('detached_at')->count())->toBe(1);
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
        expect($attachment->attached_at->lessThanOrEqualTo($hold->released_at))->toBeTrue();
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
        expect($attachment->detached_at->lessThanOrEqualTo($hold->released_at))->toBeTrue();
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
        ->and($hold->fresh()?->status)->toBe(LegalHoldStatus::Released);
});
