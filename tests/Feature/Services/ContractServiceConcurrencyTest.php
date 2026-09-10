<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\ContractCurrencyHistoryConflictException;
use App\Exceptions\ContractCustomerHistoryConflictException;
use App\Exceptions\ContractRetiredException;
use App\Models\Activity;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\ContractService;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function ensureContractConcurrencyDatabase(): void
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

function refreshContractConcurrencyDatabase(): void
{
    ensureContractConcurrencyDatabase();
    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

beforeEach(function (): void {
    refreshContractConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshContractConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function contractConcurrencyActor(TenantKey $tenant): User
{
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['contracts.create', 'contracts.update', 'contracts.retire', 'customers.read', 'customers.update'] as $permission) {
        givePermissionWithTenant($actor, $tenant->id, $permission);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $actor;
}

/**
 * @param  Closure(int, User, ContractService): void  $operation
 * @return Illuminate\Support\Collection<int, array{status: string, exception: string|null}>
 */
function runConcurrentContractOperations(
    User $actor,
    int $workerCount,
    Closure $operation,
): Illuminate\Support\Collection {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Contract concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/contract-service-concurrency-'.uniqid('', true);
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Contract concurrency directory.');
    }

    $signal = $directory.'/start.signal';
    file_put_contents($signal, 'wait');
    DB::disconnect();

    try {
        $pids = [];
        for ($worker = 1; $worker <= $workerCount; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork Contract concurrency worker.');
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
                    $operation($worker, User::query()->findOrFail($actor->id), app(ContractService::class));
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

/**
 * @param  array<string, string>  $changes
 * @return array{booking: string, update_exception: string|null, waited_on_lock: bool}
 */
function runContractServiceBookingRace(
    User $actor,
    Contract $contract,
    array $changes,
): array {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Contract history concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/contract-service-history-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Contract history concurrency directory.');
    }

    $locked = $directory.'/booking-locked';
    $release = $directory.'/release-booking';
    $updaterPidPath = $directory.'/updater-pid';
    $updaterStarted = $directory.'/updater-started';
    $bookingResult = $directory.'/booking-result';
    $updateResult = $directory.'/update-result';
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

        $updatePid = pcntl_fork();
        if ($updatePid === -1) {
            throw new RuntimeException('Unable to fork Contract updater.');
        }

        if ($updatePid === 0) {
            DB::purge();
            DB::reconnect();
            while (! is_file($locked)) {
                usleep(25_000);
            }

            $registrar = app(PermissionRegistrar::class);
            $registrar->forgetCachedPermissions();
            $registrar->setPermissionsTeamId($actor->tenant_id);
            file_put_contents($updaterPidPath, (string) DB::scalar('SELECT pg_backend_pid()'));
            file_put_contents($updaterStarted, 'started');

            try {
                app(ContractService::class)->update(
                    User::query()->findOrFail($actor->id),
                    $contract->id,
                    $changes,
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

/** @return array{contract: string, customer: string, contract_waited_on_customer: bool} */
function runContractCustomerAuditLockRace(User $actor, Customer $customer, ?Contract $contract): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for Contract concurrency evidence.');
    }

    $directory = sys_get_temp_dir().'/contract-customer-audit-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create Contract/Customer concurrency directory.');
    }

    $customerLocked = $directory.'/customer-locked';
    $releaseCustomer = $directory.'/release-customer';
    $contractPidPath = $directory.'/contract-pid';
    $contractStarted = $directory.'/contract-started';
    $contractResult = $directory.'/contract-result';
    $customerResult = $directory.'/customer-result';
    $pids = [];

    try {
        $customerPid = pcntl_fork();
        if ($customerPid === -1) {
            throw new RuntimeException('Unable to fork Customer updater.');
        }

        if ($customerPid === 0) {
            DB::purge();
            DB::reconnect();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
            DB::beginTransaction();

            try {
                Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                file_put_contents($customerLocked, 'locked');
                while (! is_file($releaseCustomer)) {
                    usleep(25_000);
                }

                app(CustomerService::class)->update(
                    User::query()->findOrFail($actor->id),
                    (int) $actor->tenant_id,
                    Customer::query()->findOrFail($customer->id),
                    ['name' => 'Concurrent customer name'],
                );
                DB::commit();
                file_put_contents($customerResult, 'success');
            } catch (Throwable $exception) {
                DB::rollBack();
                file_put_contents($customerResult, $exception::class);
            }

            exit(0);
        }
        $pids[] = $customerPid;

        $contractPid = pcntl_fork();
        if ($contractPid === -1) {
            throw new RuntimeException('Unable to fork Contract creator.');
        }

        if ($contractPid === 0) {
            DB::purge();
            DB::reconnect();
            while (! is_file($customerLocked)) {
                usleep(25_000);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(PermissionRegistrar::class)->setPermissionsTeamId($actor->tenant_id);
            file_put_contents($contractPidPath, (string) DB::scalar('SELECT pg_backend_pid()'));
            file_put_contents($contractStarted, 'started');

            try {
                $service = app(ContractService::class);
                $currentActor = User::query()->findOrFail($actor->id);
                if ($contract === null) {
                    $service->create($currentActor, [
                        'customer_id' => $customer->id,
                        'type' => 'recurring',
                        'starts_on' => '2026-10-01',
                        'ends_on' => null,
                        'billing_unit' => 'hour',
                        'unit_price' => '42.5000',
                        'currency_code' => 'EUR',
                    ]);
                } else {
                    $service->update($currentActor, $contract->id, ['customer_id' => $customer->id]);
                }
                file_put_contents($contractResult, 'success');
            } catch (Throwable $exception) {
                file_put_contents($contractResult, $exception::class);
            }

            exit(0);
        }
        $pids[] = $contractPid;

        $deadline = microtime(true) + 10;
        while (! is_file($contractStarted) || ! is_file($contractPidPath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Contract creator did not reach the race barrier.');
            }
            usleep(25_000);
        }

        DB::purge();
        DB::reconnect();
        $contractPid = (int) file_get_contents($contractPidPath);
        $waitedOnCustomer = false;
        while (microtime(true) < $deadline) {
            $waitedOnCustomer = DB::table('pg_stat_activity')
                ->where('pid', $contractPid)
                ->where('wait_event_type', 'Lock')
                ->exists();
            if ($waitedOnCustomer) {
                break;
            }
            usleep(25_000);
        }

        file_put_contents($releaseCustomer, 'release');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return [
            'contract' => trim((string) file_get_contents($contractResult)),
            'customer' => trim((string) file_get_contents($customerResult)),
            'contract_waited_on_customer' => $waitedOnCustomer,
        ];
    } finally {
        if (! is_file($releaseCustomer)) {
            file_put_contents($releaseCustomer, 'release');
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

test('concurrent retirement commits exactly one successful transition and audit', function (): void {
    $tenant = TenantKey::factory()->create();
    $actor = contractConcurrencyActor($tenant);
    $contract = Contract::factory()->create(['tenant_id' => $tenant->id]);

    $results = runConcurrentContractOperations(
        $actor,
        2,
        fn (int $worker, User $currentActor, ContractService $service) => $service->retire(
            $currentActor,
            $contract->id,
        ),
    );

    expect($results->where('status', 'success'))->toHaveCount(1)
        ->and($results->where('exception', ContractRetiredException::class))->toHaveCount(1)
        ->and($contract->fresh()?->status->value)->toBe('retired')
        ->and(Activity::query()->where('event', 'contract.retire')->count())->toBe(1);
});

test('concurrent booking history prevents customer or currency mutation with stable conflicts', function (
    string $field,
    string $exception,
): void {
    $tenant = TenantKey::factory()->create();
    $actor = contractConcurrencyActor($tenant);
    $contract = Contract::factory()->create(['tenant_id' => $tenant->id, 'currency_code' => 'EUR']);
    $replacement = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $changes = $field === 'customer_id'
        ? ['customer_id' => $replacement->id]
        : ['currency_code' => 'USD'];

    expect(runContractServiceBookingRace($actor, $contract, $changes))->toBe([
        'booking' => 'committed',
        'update_exception' => $exception,
        'waited_on_lock' => true,
    ])->and($contract->fresh()?->{$field})->toBe($field === 'customer_id' ? $contract->customer_id : 'EUR')
        ->and($contract->serviceBookings()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'contract.update')->count())->toBe(0);
})->with([
    ['customer_id', ContractCustomerHistoryConflictException::class],
    ['currency_code', ContractCurrencyHistoryConflictException::class],
]);

test('Contract association writes and Customer auditing share one deadlock-free lock order', function (string $operation): void {
    $tenant = TenantKey::factory()->create();
    $actor = contractConcurrencyActor($tenant);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $contract = $operation === 'update'
        ? Contract::factory()->create(['tenant_id' => $tenant->id])
        : null;

    expect(runContractCustomerAuditLockRace($actor, $customer, $contract))->toBe([
        'contract' => 'success',
        'customer' => 'success',
        'contract_waited_on_customer' => true,
    ])->and(Contract::query()->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'contract.'.$operation)->count())->toBe(1);
})->with(['create', 'update']);
