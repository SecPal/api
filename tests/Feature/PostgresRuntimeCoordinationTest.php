<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Jobs\BuildMerkleTreeBatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class)->group('serial');

test('database queue dispatch becomes visible only after commit and disappears on rollback', function (): void {
    config([
        'queue.default' => 'database',
        'queue.connections.database.after_commit' => true,
    ]);

    DB::beginTransaction();
    Queue::connection('database')->push(new BuildMerkleTreeBatch);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(runtimeChildProcess('queue-count'))->toBe('0');

    DB::commit();

    expect(runtimeChildProcess('queue-count'))->toBe('1');

    DB::table('jobs')->truncate();

    DB::beginTransaction();
    Queue::connection('database')->push(new BuildMerkleTreeBatch);
    DB::rollBack();

    expect(runtimeChildProcess('queue-count'))->toBe('0');
});

test('database cache and sessions survive application process boundaries and expire', function (): void {
    $cacheKey = 'runtime-cache-'.Str::uuid();
    Cache::store('database')->put($cacheKey, 'shared-value', now()->addMinute());

    expect(runtimeChildProcess('cache-get', $cacheKey))->toBe('"shared-value"');

    Cache::forgetDriver('database');
    expect(Cache::store('database')->get($cacheKey))->toBe('shared-value');

    DB::table('cache')->where('key', config('cache.prefix').$cacheKey)
        ->update(['expiration' => now()->subSecond()->getTimestamp()]);
    expect(runtimeChildProcess('cache-get', $cacheKey))->toBe('null');

    $sessionId = Str::random(40);
    $session = app('session')->driver('database');
    $session->setId($sessionId);
    $session->start();
    $session->put('shared', 'session-value');
    $session->save();

    expect(runtimeChildProcess('session-get', $sessionId))->toBe('"session-value"');

    DB::table('sessions')->where('id', $sessionId)->update([
        'last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp(),
    ]);
    expect(runtimeChildProcess('session-get', $sessionId))->toBe('null');
});

test('database cache locks coordinate independent processes and recover after expiry', function (): void {
    $lockName = 'runtime-lock-'.Str::uuid();
    $lock = Cache::store('database')->lock($lockName, 60);

    expect($lock->acquire())->toBeTrue()
        ->and(runtimeChildProcess('lock-acquire', $lockName))->toBe('0');

    $lock->release();

    expect(runtimeChildProcess('lock-acquire-release', $lockName))->toBe('1');

    expect(runtimeChildProcess('lock-acquire', $lockName))->toBe('1');
    DB::table('cache_locks')->where('key', config('cache.prefix').$lockName)
        ->update(['expiration' => now()->subSecond()->getTimestamp()]);

    expect(runtimeChildProcess('lock-acquire-release', $lockName))->toBe('1');
});

test('scheduled tasks declare deliberate singleton overlap and safe parallel semantics', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $events = collect($schedule->events())->keyBy(fn ($event): ?string => $event->description);

    $singleton = $events->get('ots-health-monitor');
    $overlapProtected = $events->get('activity-retention');
    $safeParallel = $events->get('role-expiration');

    expect($singleton)->not->toBeNull()
        ->and($singleton->onOneServer)->toBeTrue()
        ->and($singleton->mutex->store)->toBe('database')
        ->and($overlapProtected)->not->toBeNull()
        ->and($overlapProtected->onOneServer)->toBeTrue()
        ->and($overlapProtected->withoutOverlapping)->toBeTrue()
        ->and($safeParallel)->not->toBeNull()
        ->and($safeParallel->onOneServer)->toBeFalse()
        ->and($safeParallel->withoutOverlapping)->toBeFalse();
});

test('two scheduler processes share PostgreSQL launch and overlap locks', function (): void {
    $scheduledMinute = now()->startOfMinute()->toIso8601String();

    expect(runtimeChildProcess('schedule-launch', 'ots-health-monitor', $scheduledMinute))->toBe('1')
        ->and(runtimeChildProcess('schedule-launch', 'ots-health-monitor', $scheduledMinute))->toBe('0')
        ->and(runtimeChildProcess('schedule-overlap', 'activity-retention'))->toBe('1')
        ->and(runtimeChildProcess('schedule-overlap', 'activity-retention'))->toBe('0');

    DB::table('cache_locks')->update(['expiration' => now()->subSecond()->getTimestamp()]);

    expect(runtimeChildProcess('schedule-overlap', 'activity-retention'))->toBe('1');
});

function runtimeChildProcess(string ...$arguments): string
{
    $script = <<<'PHP'
    $basePath = $argv[1];
    $operation = $argv[2];
    require $basePath.'/vendor/autoload.php';
    $application = require $basePath.'/bootstrap/app.php';
    $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $result = match ($operation) {
        'queue-count' => (string) Illuminate\Support\Facades\DB::table('jobs')->count(),
        'cache-get' => json_encode(Illuminate\Support\Facades\Cache::store('database')->get($argv[3]), JSON_THROW_ON_ERROR),
        'session-get' => (function () use ($application, $argv): string {
            $session = $application->make('session')->driver('database');
            $session->setId($argv[3]);
            $session->start();

            return json_encode($session->get('shared'), JSON_THROW_ON_ERROR);
        })(),
        'lock-acquire' => Illuminate\Support\Facades\Cache::store('database')->lock($argv[3], 60)->acquire() ? '1' : '0',
        'lock-acquire-release' => (function () use ($argv): string {
            $lock = Illuminate\Support\Facades\Cache::store('database')->lock($argv[3], 60);
            $acquired = $lock->acquire();
            if ($acquired) {
                $lock->release();
            }

            return $acquired ? '1' : '0';
        })(),
        'schedule-launch' => (function () use ($application, $argv): string {
            $schedule = $application->make(Illuminate\Console\Scheduling\Schedule::class);
            $event = collect($schedule->events())->first(
                fn ($candidate): bool => $candidate->description === $argv[3],
            );
            if ($event === null) {
                throw new RuntimeException('Scheduled event not found.');
            }

            $shouldLaunch = ! $event->onOneServer
                || $schedule->serverShouldRun($event, Illuminate\Support\Carbon::parse($argv[4]));

            return $shouldLaunch ? '1' : '0';
        })(),
        'schedule-overlap' => (function () use ($application, $argv): string {
            $schedule = $application->make(Illuminate\Console\Scheduling\Schedule::class);
            $event = collect($schedule->events())->first(
                fn ($candidate): bool => $candidate->description === $argv[3],
            );
            if ($event === null) {
                throw new RuntimeException('Scheduled event not found.');
            }

            $shouldLaunch = ! $event->withoutOverlapping || $event->mutex->create($event);

            return $shouldLaunch ? '1' : '0';
        })(),
        default => throw new RuntimeException('Unknown runtime probe operation.'),
    };

    echo $result;
    PHP;

    $process = new Process(
        [PHP_BINARY, '-r', $script, base_path(), ...$arguments],
        base_path(),
        runtimeChildEnvironment(),
    );
    $process->setTimeout(20)->mustRun();

    return trim($process->getOutput());
}

/**
 * @return array<string, string>
 */
function runtimeChildEnvironment(): array
{
    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'CACHE_STORE' => 'array',
        'CACHE_PREFIX' => (string) config('cache.prefix'),
        'DB_CONNECTION' => 'pgsql',
        'QUEUE_CONNECTION' => 'database',
        'SECPAL_TEST_DATABASE' => (string) config('database.connections.pgsql.database'),
        'SECPAL_TEST_SCHEMA' => (string) DB::scalar('select current_schema()'),
        'SESSION_DRIVER' => 'database',
    ];
}
