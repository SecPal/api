<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\ServiceBookingInvoicedException;
use App\Exceptions\ServiceBookingRetiredException;
use App\Models\Activity;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\ServiceBookingService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function refreshServiceBookingConcurrencyDatabase(): void
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
    refreshServiceBookingConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshServiceBookingConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function serviceBookingConcurrencyActor(TenantKey $tenant): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['service_bookings.update', 'service_bookings.retire'] as $permission) {
        givePermissionWithTenant($actor, $tenant->id, $permission);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $actor;
}

/**
 * @param  Closure(User, ServiceBookingService): void  $operation
 * @return Illuminate\Support\Collection<int, array{status: string, exception: string|null}>
 */
function runConcurrentServiceBookingOperations(User $actor, Closure $operation): Illuminate\Support\Collection
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Service Booking concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/service-booking-concurrency-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Service Booking concurrency directory.');
    }
    $signal = $directory.'/start.signal';
    file_put_contents($signal, 'wait');
    DB::disconnect();

    try {
        $pids = [];
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork Service Booking concurrency worker.');
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
                    $operation(User::query()->findOrFail($actor->id), app(ServiceBookingService::class));
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

        return collect([1, 2])->map(function (int $worker) use ($directory): array {
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

/**
 * @return array{transition: string, update_exception: string, waited_on_lock: bool}
 */
function runTransitionBeforeServiceBookingUpdate(
    User $actor,
    ServiceBooking $booking,
    string $transition,
): array {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Service Booking transition concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/service-booking-transition-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Service Booking transition directory.');
    }
    $locked = $directory.'/locked';
    $release = $directory.'/release';
    $updaterPidPath = $directory.'/updater-pid';
    $updaterStarted = $directory.'/updater-started';
    $transitionResult = $directory.'/transition-result';
    $updateResult = $directory.'/update-result';
    $pids = [];

    try {
        $transitionPid = pcntl_fork();
        if ($transitionPid === -1) {
            throw new RuntimeException('Unable to fork Service Booking transition worker.');
        }
        if ($transitionPid === 0) {
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();
            try {
                ServiceBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
                file_put_contents($locked, 'locked');
                while (! is_file($release)) {
                    usleep(25_000);
                }
                $attributes = $transition === 'invoice'
                    ? ['invoice_state' => 'invoiced', 'invoiced_at' => now()]
                    : ['status' => 'retired', 'retired_at' => now()];
                DB::table('service_bookings')->where('id', $booking->id)->update($attributes);
                DB::commit();
                file_put_contents($transitionResult, 'committed');
            } catch (Throwable $exception) {
                DB::rollBack();
                file_put_contents($transitionResult, $exception::class);
            }
            exit(0);
        }
        $pids[] = $transitionPid;

        $updatePid = pcntl_fork();
        if ($updatePid === -1) {
            throw new RuntimeException('Unable to fork Service Booking update worker.');
        }
        if ($updatePid === 0) {
            DB::purge();
            DB::reconnect();
            while (! is_file($locked)) {
                usleep(25_000);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
            file_put_contents($updaterPidPath, (string) DB::scalar('SELECT pg_backend_pid()'));
            file_put_contents($updaterStarted, 'started');
            try {
                app(ServiceBookingService::class)->update(
                    User::query()->findOrFail($actor->id),
                    $booking->id,
                    ['quantity' => '9.0000'],
                );
                file_put_contents($updateResult, 'none');
            } catch (Throwable $exception) {
                file_put_contents($updateResult, $exception::class);
            }
            exit(0);
        }
        $pids[] = $updatePid;

        $deadline = microtime(true) + 10;
        while (! is_file($updaterStarted) || ! is_file($updaterPidPath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Service Booking updater did not reach the race barrier.');
            }
            usleep(25_000);
        }

        DB::purge();
        DB::reconnect();
        $updaterPid = (int) file_get_contents($updaterPidPath);
        $waitedOnLock = false;
        while (microtime(true) < $deadline) {
            $waitedOnLock = DB::table('pg_stat_activity')
                ->where('pid', $updaterPid)
                ->where('wait_event_type', 'Lock')
                ->exists();
            if ($waitedOnLock) {
                break;
            }
            usleep(25_000);
        }

        file_put_contents($release, 'release');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return [
            'transition' => trim((string) file_get_contents($transitionResult)),
            'update_exception' => trim((string) file_get_contents($updateResult)),
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

test('concurrent retirement commits exactly one terminal transition and audit', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = serviceBookingConcurrencyActor($tenant);
    $booking = ServiceBooking::factory()->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentServiceBookingOperations(
        $actor,
        fn (User $currentActor, ServiceBookingService $service) => $service->retire(
            $currentActor,
            $booking->id,
        ),
    );

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', ServiceBookingRetiredException::class))->toHaveCount(1)
        ->and($booking->fresh()?->status->value)->toBe('retired')
        ->and(Activity::query()->where('event', 'service_booking.retire')->count())->toBe(1);
});

test('a committed invoice or retirement transition prevents stale financial update', function (
    string $transition,
    string $exception,
): void {
    $tenant = TenantKey::factory()->create();
    $actor = serviceBookingConcurrencyActor($tenant);
    $booking = ServiceBooking::factory()->create([
        'tenant_id' => $tenant->id,
        'quantity' => '2.0000',
    ]);

    expect(runTransitionBeforeServiceBookingUpdate($actor, $booking, $transition))->toBe([
        'transition' => 'committed',
        'update_exception' => $exception,
        'waited_on_lock' => true,
    ])->and($booking->fresh()?->quantity)->toBe('2.0000')
        ->and(Activity::query()->where('event', 'service_booking.update')->count())->toBe(0);
})->with([
    ['invoice', ServiceBookingInvoicedException::class],
    ['retire', ServiceBookingRetiredException::class],
]);

test('an update that commits first remains valid evidence for a later invoice transition', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = serviceBookingConcurrencyActor($tenant);
    $booking = ServiceBooking::factory()->create(['tenant_id' => $tenant->id]);

    app(ServiceBookingService::class)->update($actor, $booking->id, ['quantity' => '3.0000']);
    DB::table('service_bookings')->where('id', $booking->id)->update([
        'invoice_state' => 'invoiced',
        'invoiced_at' => now(),
    ]);

    expect($booking->fresh()?->quantity)->toBe('3.0000')
        ->and($booking->fresh()?->invoice_state->value)->toBe('invoiced')
        ->and(Activity::query()->where('event', 'service_booking.update')->count())->toBe(1);
});
