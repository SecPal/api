<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\CostCenterAllocationInactiveTargetException;
use App\Exceptions\InternalCostCenterConflictException;
use App\Models\Activity;
use App\Models\CostCenterAllocation;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\CostCenterAllocationService;
use App\Services\InternalCostCenterService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function refreshCostCenterConcurrencyDatabase(): void
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
    refreshCostCenterConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshCostCenterConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function costCenterConcurrencyActor(TenantKey $tenant): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['internal_cost_centers.deactivate', 'cost_center_allocations.update'] as $permission) {
        givePermissionWithTenant($actor, $tenant->id, $permission);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $actor;
}

/**
 * @param  array<string, Closure(User, InternalCostCenterService, CostCenterAllocationService): void>  $operations
 * @return Illuminate\Support\Collection<int, array{operation: string, status: string, exception: string|null}>
 */
function runConcurrentCostCenterOperations(User $actor, array $operations): Illuminate\Support\Collection
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Cost Center concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/cost-center-service-concurrency-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Cost Center concurrency directory.');
    }
    $signal = $directory.'/start.signal';
    file_put_contents($signal, 'wait');
    DB::disconnect();

    try {
        $pids = [];
        foreach ($operations as $name => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork Cost Center concurrency worker.');
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
                        app(InternalCostCenterService::class),
                        app(CostCenterAllocationService::class),
                    );
                    $result = ['operation' => $name, 'status' => 'success', 'exception' => null];
                } catch (Throwable $exception) {
                    $result = ['operation' => $name, 'status' => 'failure', 'exception' => $exception::class];
                }
                file_put_contents(
                    $directory.'/result-'.$name.'.json',
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

        return collect(array_keys($operations))->map(function (string $name) use ($directory): array {
            /** @var array{operation: string, status: string, exception: string|null} $result */
            $result = json_decode(
                (string) file_get_contents($directory.'/result-'.$name.'.json'),
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

test('concurrent deactivation commits exactly one terminal transition and audit', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = costCenterConcurrencyActor($tenant);
    $center = InternalCostCenter::factory()->forTenant($tenant->id)->create();

    $results = runConcurrentCostCenterOperations($actor, [
        'first' => fn (User $current, InternalCostCenterService $centers) => $centers->deactivate($current, $center->id),
        'second' => fn (User $current, InternalCostCenterService $centers) => $centers->deactivate($current, $center->id),
    ]);

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', InternalCostCenterConflictException::class))->toHaveCount(1)
        ->and($center->fresh()?->status->value)->toBe('inactive')
        ->and(Activity::query()->where('event', 'internal_cost_center.deactivate')->count())->toBe(1);
});

test('concurrent complete replacements commit one whole snapshot without merging', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = costCenterConcurrencyActor($tenant);
    $booking = ServiceBooking::factory()->forTenant($tenant->id)->create();
    $centers = InternalCostCenter::factory()->count(2)->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentCostCenterOperations($actor, [
        'first' => fn (User $current, InternalCostCenterService $_, CostCenterAllocationService $allocations) => $allocations->replace($current, $booking->id, [[
            'internal_cost_center_id' => $centers[0]->id,
            'share_bps' => 10000,
        ]]),
        'second' => fn (User $current, InternalCostCenterService $_, CostCenterAllocationService $allocations) => $allocations->replace($current, $booking->id, [[
            'internal_cost_center_id' => $centers[1]->id,
            'share_bps' => 10000,
        ]]),
    ]);

    $snapshot = CostCenterAllocation::query()->where('service_booking_id', $booking->id)->get();
    expect($results->where('status', 'success'))->toHaveCount(2)
        ->and($snapshot)->toHaveCount(1)
        ->and($snapshot->sole()->share_bps)->toBe(10000)
        ->and($centers->pluck('id')->contains($snapshot->sole()->internal_cost_center_id))->toBeTrue()
        ->and(Activity::query()->where('event', 'cost_center_allocation.replace')->count())->toBe(2);
});

test('deactivation and replacement race cannot newly allocate after deactivation wins', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = costCenterConcurrencyActor($tenant);
    $booking = ServiceBooking::factory()->forTenant($tenant->id)->create();
    $original = CostCenterAllocation::factory()->createCompleteSplit($booking)->sole();
    $target = InternalCostCenter::factory()->forTenant($tenant->id)->create();

    $results = runConcurrentCostCenterOperations($actor, [
        'deactivate' => fn (User $current, InternalCostCenterService $centers) => $centers->deactivate($current, $target->id),
        'replace' => fn (User $current, InternalCostCenterService $_, CostCenterAllocationService $allocations) => $allocations->replace($current, $booking->id, [[
            'internal_cost_center_id' => $target->id,
            'share_bps' => 10000,
        ]]),
    ]);

    $replacement = $results->firstWhere('operation', 'replace');
    $snapshot = CostCenterAllocation::query()->where('service_booking_id', $booking->id)->sole();
    expect($results->firstWhere('operation', 'deactivate'))->toMatchArray(['status' => 'success'])
        ->and($target->fresh()?->status->value)->toBe('inactive')
        ->and($snapshot->share_bps)->toBe(10000);

    if ($replacement['status'] === 'failure') {
        expect($replacement['exception'])->toBe(CostCenterAllocationInactiveTargetException::class)
            ->and($snapshot->id)->toBe($original->id);
    } else {
        expect($snapshot->internal_cost_center_id)->toBe($target->id);
    }
});
