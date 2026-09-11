<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\WorkInstructionConflictException;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstruction;
use App\Services\WorkInstructionService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function refreshWorkInstructionConcurrencyDatabase(): void
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
    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

beforeEach(function (): void {
    refreshWorkInstructionConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshWorkInstructionConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function workInstructionConcurrencyActor(TenantKey $tenant): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach ([
        'work_instructions.create',
        'work_instructions.update',
        'work_instructions.publish',
        'work_instructions.archive',
    ] as $permission) {
        givePermissionWithTenant($actor, $tenant->id, $permission);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $actor;
}

/**
 * @param  Closure(User, WorkInstructionService, int): void  $operation
 * @return Illuminate\Support\Collection<int, array{worker: int, status: string, exception: string|null, message: string|null}>
 */
function runConcurrentWorkInstructionOperations(User $actor, Closure $operation): Illuminate\Support\Collection
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Work Instruction concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/work-instruction-concurrency-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Work Instruction concurrency directory.');
    }
    $signal = $directory.'/start.signal';
    file_put_contents($signal, 'wait');
    DB::disconnect();

    try {
        $pids = [];
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork Work Instruction concurrency worker.');
            }
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
                while (trim((string) file_get_contents($signal)) !== 'go') {
                    usleep(10_000);
                }

                try {
                    $operation(
                        User::query()->findOrFail($actor->id),
                        app(WorkInstructionService::class),
                        $worker,
                    );
                    $result = [
                        'worker' => $worker,
                        'status' => 'success',
                        'exception' => null,
                        'message' => null,
                    ];
                } catch (Throwable $exception) {
                    $result = [
                        'worker' => $worker,
                        'status' => 'failure',
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ];
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

        return collect([1, 2])->map(function (int $worker) use ($directory): array {
            /** @var array{worker: int, status: string, exception: string|null, message: string|null} $result */
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

/** @return array{published: bool, update_exception: string, update_message: string, waited_on_lock: bool} */
function runCommittedPublishBeforeWorkInstructionUpdate(
    User $actor,
    WorkInstruction $instruction,
): array {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Work Instruction transition concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/work-instruction-patch-publish-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Work Instruction transition directory.');
    }
    $locked = $directory.'/locked';
    $release = $directory.'/release';
    $started = $directory.'/started';
    $backendPidPath = $directory.'/backend-pid';
    $resultPath = $directory.'/result.json';
    $publishResultPath = $directory.'/publish-result';
    $pids = [];
    DB::disconnect();

    try {
        $publishPid = pcntl_fork();
        if ($publishPid === -1) {
            throw new RuntimeException('Unable to fork Work Instruction publication worker.');
        }
        if ($publishPid === 0) {
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();
            try {
                WorkInstruction::query()->whereKey($instruction->id)->lockForUpdate()->firstOrFail();
                file_put_contents($locked, 'locked');
                while (! is_file($release)) {
                    usleep(25_000);
                }
                DB::table('work_instructions')->where('id', $instruction->id)->update([
                    'status' => 'published',
                    'published_at' => now(),
                    'published_by_user_id' => $actor->id,
                ]);
                DB::commit();
                file_put_contents($publishResultPath, 'published');
            } catch (Throwable $exception) {
                DB::rollBack();
                file_put_contents($publishResultPath, $exception::class);
            }
            exit(0);
        }
        $pids[] = $publishPid;

        $updatePid = pcntl_fork();
        if ($updatePid === -1) {
            throw new RuntimeException('Unable to fork Work Instruction update worker.');
        }
        if ($updatePid === 0) {
            DB::purge();
            DB::reconnect();
            while (! is_file($locked)) {
                usleep(25_000);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
            file_put_contents($backendPidPath, (string) DB::scalar('SELECT pg_backend_pid()'));
            file_put_contents($started, 'started');
            try {
                app(WorkInstructionService::class)->update(
                    User::query()->findOrFail($actor->id),
                    $instruction->id,
                    ['title' => 'Forbidden stale rewrite'],
                );
                $result = ['exception' => 'none', 'message' => ''];
            } catch (Throwable $exception) {
                $result = ['exception' => $exception::class, 'message' => $exception->getMessage()];
            }
            file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        }
        $pids[] = $updatePid;

        $deadline = microtime(true) + 10;
        while (! is_file($started) || ! is_file($backendPidPath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Work Instruction updater did not reach the race barrier.');
            }
            usleep(25_000);
        }

        DB::purge();
        DB::reconnect();
        $backendPid = (int) file_get_contents($backendPidPath);
        $waitedOnLock = false;
        while (microtime(true) < $deadline) {
            $waitedOnLock = DB::table('pg_stat_activity')
                ->where('pid', $backendPid)
                ->where('wait_event_type', 'Lock')
                ->exists();
            if ($waitedOnLock) {
                break;
            }
            usleep(25_000);
        }

        file_put_contents($release, 'release');
        foreach ($pids as $pid) {
            expect(pcntl_waitpid($pid, $status))->toBe($pid)
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $pids = [];
        DB::purge();
        DB::reconnect();

        /** @var array{exception: string, message: string} $result */
        $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);

        return [
            'published' => trim((string) file_get_contents($publishResultPath)) === 'published'
                && $instruction->fresh()?->status->value === 'published',
            'update_exception' => $result['exception'],
            'update_message' => $result['message'],
            'waited_on_lock' => $waitedOnLock,
        ];
    } finally {
        if (! is_file($release)) {
            file_put_contents($release, 'release');
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::purge();
        DB::reconnect();
    }
}

test('concurrent submit publish and archive transitions commit exactly one winner', function (string $transition, string $state, string $event): void {
    $tenant = TenantKey::factory()->create();
    $actor = workInstructionConcurrencyActor($tenant);
    $factory = WorkInstruction::factory()->state(['tenant_id' => $tenant->id]);
    $instruction = match ($state) {
        'draft' => $factory->draft()->create(),
        'in_review' => $factory->inReview()->create(),
        'published' => $factory->published()->create(),
    };

    $results = runConcurrentWorkInstructionOperations(
        $actor,
        fn (User $currentActor, WorkInstructionService $service): WorkInstruction => $service->{$transition}(
            $currentActor,
            $instruction->id,
        ),
    );

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', WorkInstructionConflictException::class))->toHaveCount(1)
        ->and(Activity::query()->where('event', $event)->count())->toBe(1);
})->with([
    ['submitForReview', 'draft', 'work_instruction.submit_for_review'],
    ['publish', 'in_review', 'work_instruction.publish'],
    ['archive', 'published', 'work_instruction.archive'],
]);

test('a committed publication prevents a stale PATCH from changing frozen content', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = workInstructionConcurrencyActor($tenant);
    $instruction = WorkInstruction::factory()->inReview()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Committed final title',
    ]);

    expect(runCommittedPublishBeforeWorkInstructionUpdate($actor, $instruction))->toBe([
        'published' => true,
        'update_exception' => WorkInstructionConflictException::class,
        'update_message' => 'The Work Instruction changed in a concurrent state transition.',
        'waited_on_lock' => true,
    ])->and($instruction->fresh()?->title)->toBe('Committed final title')
        ->and(Activity::query()->where('event', 'work_instruction.update')->exists())->toBeFalse();
});

test('concurrent duplicate creation commits one resource and one bounded audit', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = workInstructionConcurrencyActor($tenant);

    $results = runConcurrentWorkInstructionOperations(
        $actor,
        fn (User $currentActor, WorkInstructionService $service) => $service->create($currentActor, [
            'instruction_number' => 'WI-CONCURRENT',
            'title' => 'Concurrent creation',
            'body' => 'Only one row may commit.',
            'locale' => 'en',
        ]),
    );

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', WorkInstructionConflictException::class))->toHaveCount(1)
        ->and(WorkInstruction::query()->where('instruction_number', 'WI-CONCURRENT')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'work_instruction.create')->count())->toBe(1);
});

test('publish and archive races never commit an impossible lifecycle state', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = workInstructionConcurrencyActor($tenant);
    $instruction = WorkInstruction::factory()->inReview()->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentWorkInstructionOperations(
        $actor,
        function (User $currentActor, WorkInstructionService $service, int $worker) use ($instruction): void {
            if ($worker === 1) {
                $service->publish($currentActor, $instruction->id);

                return;
            }
            $service->archive($currentActor, $instruction->id);
        },
    );

    $current = $instruction->fresh();
    expect($results->firstWhere('worker', 1)['status'])->toBe('success')
        ->and($results->firstWhere('worker', 2)['status'])->toBeIn(['success', 'failure'])
        ->and($current?->status->value)->toBeIn(['published', 'archived'])
        ->and($current?->published_at)->not->toBeNull();
    if ($current?->status->value === 'archived') {
        expect($current->archived_at?->greaterThanOrEqualTo($current->published_at))->toBeTrue()
            ->and(Activity::query()->where('event', 'work_instruction.publish')->value('id'))
            ->toBeLessThan(Activity::query()->where('event', 'work_instruction.archive')->value('id'));
    } else {
        expect($current?->archived_at)->toBeNull();
    }
});
