<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Contract;
use App\Models\Customer;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('serial');

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

/**
 * @param  array<int, Closure(): void>  $writers
 * @return list<string>
 */
function runConcurrentAllocationWriters(array $writers): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for allocation concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/allocation-concurrency-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create allocation concurrency directory.');
    }

    $start = $directory.'/start';
    file_put_contents($start, 'wait');
    $pids = [];

    try {
        foreach ($writers as $index => $writer) {
            $result = $directory.'/result-'.$index;
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork allocation writer.');
            }

            if ($pid === 0) {
                if (function_exists('xdebug_stop_code_coverage')) {
                    xdebug_stop_code_coverage(false);
                }

                DB::purge();
                DB::reconnect();
                file_put_contents($directory.'/ready-'.$index, 'ready');

                while (trim((string) file_get_contents($start)) !== 'go') {
                    usleep(25_000);
                }

                try {
                    DB::transaction($writer);
                    file_put_contents($result, 'committed');
                } catch (Throwable) {
                    file_put_contents($result, 'rejected');
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        $deadline = microtime(true) + 10;
        while (count(glob($directory.'/ready-*') ?: []) !== count($writers)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Allocation writers did not reach the ready barrier.');
            }

            usleep(25_000);
        }

        file_put_contents($start, 'go');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach (array_keys($writers) as $index) {
            $results[] = trim((string) file_get_contents($directory.'/result-'.$index));
        }

        sort($results);

        return $results;
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::purge();
        DB::reconnect();
    }
}

/** @return array{booking: string, reassignment: string, waited_on_lock: bool} */
function runContractBookingHistoryRace(Contract $contract, Customer $replacementCustomer): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for contract history concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/contract-history-concurrency-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create contract history concurrency directory.');
    }

    $locked = $directory.'/booking-locked';
    $release = $directory.'/release-booking';
    $updaterPidPath = $directory.'/updater-pid';
    $updaterStarted = $directory.'/updater-started';
    $bookingResult = $directory.'/booking-result';
    $reassignmentResult = $directory.'/reassignment-result';
    $pids = [];

    try {
        $bookingPid = pcntl_fork();
        if ($bookingPid === -1) {
            throw new RuntimeException('Unable to fork booking writer.');
        }

        if ($bookingPid === 0) {
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();

            try {
                DB::table('service_bookings')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $contract->tenant_id,
                    'contract_id' => $contract->id,
                    'service_date' => '2026-09-10',
                    'quantity' => '1.0000',
                    'billing_unit' => 'hour',
                    'unit_price' => '42.0000',
                    'currency_code' => $contract->currency_code,
                    'invoice_state' => 'unbilled',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                file_put_contents($locked, 'locked');

                while (! is_file($release)) {
                    usleep(25_000);
                }

                DB::commit();
                file_put_contents($bookingResult, 'committed');
            } catch (Throwable) {
                DB::rollBack();
                file_put_contents($bookingResult, 'rejected');
            }

            exit(0);
        }
        $pids[] = $bookingPid;

        $reassignmentPid = pcntl_fork();
        if ($reassignmentPid === -1) {
            throw new RuntimeException('Unable to fork contract updater.');
        }

        if ($reassignmentPid === 0) {
            DB::purge();
            DB::reconnect();

            while (! is_file($locked)) {
                usleep(25_000);
            }

            DB::beginTransaction();
            file_put_contents($updaterPidPath, (string) DB::scalar('SELECT pg_backend_pid()'));
            file_put_contents($updaterStarted, 'started');

            try {
                DB::table('contracts')->where('id', $contract->id)->update([
                    'customer_id' => $replacementCustomer->id,
                ]);
                DB::commit();
                file_put_contents($reassignmentResult, 'committed');
            } catch (Throwable) {
                DB::rollBack();
                file_put_contents($reassignmentResult, 'rejected');
            }

            exit(0);
        }
        $pids[] = $reassignmentPid;

        $deadline = microtime(true) + 10;
        while (! is_file($updaterStarted) || ! is_file($updaterPidPath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Contract updater did not reach the race barrier.');
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
            'booking' => trim((string) file_get_contents($bookingResult)),
            'reassignment' => trim((string) file_get_contents($reassignmentResult)),
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

test('concurrent complete reconciliations cannot commit an invalid combined split', function (): void {
    $booking = ServiceBooking::factory()->create();
    $writerCenters = collect(range(1, 2))->map(fn (): InternalCostCenter => InternalCostCenter::factory()->create([
        'tenant_id' => $booking->tenant_id,
    ]));

    $writers = [
        function () use ($booking, $writerCenters): void {
            DB::table('cost_center_allocations')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $booking->tenant_id,
                'service_booking_id' => $booking->id,
                'internal_cost_center_id' => $writerCenters[0]->id,
                'share_bps' => 10000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        },
        function () use ($booking, $writerCenters): void {
            DB::table('cost_center_allocations')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $booking->tenant_id,
                'service_booking_id' => $booking->id,
                'internal_cost_center_id' => $writerCenters[1]->id,
                'share_bps' => 10000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        },
    ];

    expect(runConcurrentAllocationWriters($writers))->toBe(['committed', 'rejected'])
        ->and((int) DB::table('cost_center_allocations')->where('service_booking_id', $booking->id)->sum('share_bps'))
        ->toBe(10000)
        ->and(DB::table('cost_center_allocations')->where('service_booking_id', $booking->id)->count())
        ->toBe(1);
});

test('concurrent duplicate center allocation produces one committed identity maximum', function (): void {
    $booking = ServiceBooking::factory()->create();
    $center = InternalCostCenter::factory()->create(['tenant_id' => $booking->tenant_id]);

    $writer = function () use ($booking, $center): void {
        DB::table('cost_center_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $booking->tenant_id,
            'service_booking_id' => $booking->id,
            'internal_cost_center_id' => $center->id,
            'share_bps' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    expect(runConcurrentAllocationWriters([$writer, $writer]))->toBe(['committed', 'rejected'])
        ->and(DB::table('cost_center_allocations')->where('service_booking_id', $booking->id)->count())
        ->toBe(1);
});

test('booking creation serializes contract reassignment against committed history', function (): void {
    $contract = Contract::factory()->create();
    $replacementCustomer = Customer::factory()->create(['tenant_id' => $contract->tenant_id]);

    expect(runContractBookingHistoryRace($contract, $replacementCustomer))->toBe([
        'booking' => 'committed',
        'reassignment' => 'rejected',
        'waited_on_lock' => true,
    ])->and($contract->fresh()?->customer_id)->not->toBe($replacementCustomer->id)
        ->and($contract->serviceBookings()->count())->toBe(1);
});
