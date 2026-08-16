<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

/**
 * Align the default connection with Laravel's parallel worker database (e.g. testing_test_3).
 * This file does not use RefreshDatabase, so without this call every worker would run
 * migrate:fresh against the shared base name from phpunit.xml and deadlock under --parallel.
 */
function ensureParallelWorkerDatabase(): void
{
    $token = ParallelTesting::token();
    if ($token === false || $token === '') {
        return;
    }

    $connectionName = (string) config('database.default');
    $currentDatabase = (string) config("database.connections.{$connectionName}.database");
    $suffix = '_test_'.$token;

    if (str_ends_with($currentDatabase, $suffix)) {
        return;
    }

    $rootDatabase = preg_replace('/_test_\d+$/', '', $currentDatabase);
    if ($rootDatabase === '') {
        return;
    }

    config()->set("database.connections.{$connectionName}.database", $rootDatabase.$suffix);
    config()->set("database.connections.{$connectionName}.url", null);

    DB::purge();
    DB::reconnect();
}

function refreshEmployeeNumberConcurrencyDatabase(): void
{
    ensureParallelWorkerDatabase();

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function assertChildProcessSucceeded(int $childPid): void
{
    expect(pcntl_waitpid($childPid, $status))->toBe($childPid);
    expect(pcntl_wifexited($status))->toBeTrue();
    expect(pcntl_wifsignaled($status))->toBeFalse();
    expect(pcntl_wexitstatus($status))->toBe(0);
}

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property LegalEntity $legalEntity
 * @property Establishment $establishment
 */
beforeEach(function (): void {
    refreshEmployeeNumberConcurrencyDatabase();

    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create();
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
    givePermissionWithTenant($this->user, $this->tenant->id, 'employees.read_salary');
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $this->legalEntity = LegalEntity::factory()->forTenant($this->tenant->id)->create();
    $this->establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);

});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshEmployeeNumberConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('concurrent employee creation requests produce distinct employee numbers', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the employee number concurrency regression test.');
    }

    $requestCount = 8;
    $synchronizationDirectory = sys_get_temp_dir().'/employee-number-concurrency-'.uniqid('', true);

    if (! mkdir($synchronizationDirectory) && ! is_dir($synchronizationDirectory)) {
        $this->fail('Unable to create synchronization directory for concurrency test.');
    }

    try {
        $startSignalPath = $synchronizationDirectory.'/start.signal';
        file_put_contents($startSignalPath, 'wait');

        $childPids = [];

        for ($index = 1; $index <= $requestCount; $index++) {
            $resultPath = $synchronizationDirectory."/result-{$index}.json";
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to fork concurrency test child process.');
            }

            if ($pid === 0) {
                if (function_exists('xdebug_stop_code_coverage')) {
                    xdebug_stop_code_coverage(false);
                }

                DB::purge();
                DB::reconnect();
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                while (trim((string) file_get_contents($startSignalPath)) !== 'go') {
                    usleep(100_000);
                }

                $response = $this->withToken($this->token)
                    ->postJson('/v1/employees', employeeCreationPayload(
                        $this->legalEntity->id,
                        $this->establishment->id,
                        $index,
                    ));

                file_put_contents($resultPath, json_encode([
                    'status' => $response->getStatusCode(),
                    'employee_number' => $response->json('data.employee_number'),
                    'body' => $response->json(),
                ], JSON_THROW_ON_ERROR));

                exit(0);
            }

            $childPids[] = $pid;
        }

        file_put_contents($startSignalPath, 'go');

        foreach ($childPids as $childPid) {
            assertChildProcessSucceeded($childPid);
        }

        $results = collect(range(1, $requestCount))
            ->map(fn (int $index): array => json_decode(
                (string) file_get_contents($synchronizationDirectory."/result-{$index}.json"),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ));

        expect($results->pluck('status')->all())->toBe(array_fill(0, $requestCount, 201));

        /** @var array<int, string> $employeeNumbers */
        $employeeNumbers = $results->pluck('employee_number')->all();

        expect(count(array_unique($employeeNumbers)))->toBe($requestCount);
        expect(Employee::query()->count())->toBe($requestCount);
    } finally {
        if (isset($startSignalPath) && file_exists($startSignalPath)) {
            unlink($startSignalPath);
        }

        foreach (glob($synchronizationDirectory.'/result-*.json') ?: [] as $path) {
            unlink($path);
        }

        if (is_dir($synchronizationDirectory)) {
            rmdir($synchronizationDirectory);
        }
    }
});

/**
 * @return array<string, int|string|float>
 */
function employeeCreationPayload(string $legalEntityId, string $establishmentId, int $index): array
{
    return [
        'first_name' => "Concurrent-{$index}",
        'last_name' => 'Employee',
        'email' => "concurrent-{$index}@secpal.dev",
        'date_of_birth' => '1990-01-01',
        'position' => 'Security Guard',
        'status' => Employee::STATUS_PRE_CONTRACT,
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'weekly_hours' => 40,
        'hourly_rate' => 15.00,
        'legal_entity_id' => $legalEntityId,
        'establishment_id' => $establishmentId,
        'sachkunde_type' => 'none',
        'work_permit_type' => 'none',
        'criminal_record_status' => 'valid',
        'management_level' => 0,
    ];
}
