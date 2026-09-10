<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\CostCenterAllocation;
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

test('concurrent complete reconciliations cannot commit an invalid combined split', function (): void {
    $booking = ServiceBooking::factory()->create();
    $initial = InternalCostCenter::factory()->create(['tenant_id' => $booking->tenant_id]);
    CostCenterAllocation::factory()->create([
        'tenant_id' => $booking->tenant_id,
        'service_booking_id' => $booking->id,
        'internal_cost_center_id' => $initial->id,
    ]);

    $writerCenters = collect(range(1, 4))->map(fn (): InternalCostCenter => InternalCostCenter::factory()->create([
        'tenant_id' => $booking->tenant_id,
    ]));

    $writers = [
        function () use ($booking, $writerCenters): void {
            DB::table('cost_center_allocations')->where('service_booking_id', $booking->id)->delete();
            usleep(300_000);
            foreach ([6000, 4000] as $index => $share) {
                DB::table('cost_center_allocations')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $booking->tenant_id,
                    'service_booking_id' => $booking->id,
                    'internal_cost_center_id' => $writerCenters[$index]->id,
                    'share_bps' => $share,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        },
        function () use ($booking, $writerCenters): void {
            DB::table('cost_center_allocations')->where('service_booking_id', $booking->id)->delete();
            usleep(300_000);
            foreach ([5000, 5000] as $index => $share) {
                DB::table('cost_center_allocations')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $booking->tenant_id,
                    'service_booking_id' => $booking->id,
                    'internal_cost_center_id' => $writerCenters[$index + 2]->id,
                    'share_bps' => $share,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        },
    ];

    expect(runConcurrentAllocationWriters($writers))->toBe(['committed', 'rejected'])
        ->and((int) DB::table('cost_center_allocations')->where('service_booking_id', $booking->id)->sum('share_bps'))
        ->toBe(10000);
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
