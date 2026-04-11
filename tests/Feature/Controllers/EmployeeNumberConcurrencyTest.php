<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property OrganizationalUnit $organizationalUnit
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

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
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
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
            DB::disconnect();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            while (trim((string) file_get_contents($startSignalPath)) !== 'go') {
                usleep(10_000);
            }

            $response = $this->withToken($this->token)
                ->postJson('/v1/employees', employeeCreationPayload($this->organizationalUnit->id, $index));

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
        pcntl_waitpid($childPid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
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

    unlink($startSignalPath);

    foreach (range(1, $requestCount) as $index) {
        unlink($synchronizationDirectory."/result-{$index}.json");
    }

    rmdir($synchronizationDirectory);
});

/**
 * @return array<string, int|string|float>
 */
function employeeCreationPayload(string $organizationalUnitId, int $index): array
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
        'organizational_unit_id' => $organizationalUnitId,
        'sachkunde_type' => 'none',
        'work_permit_type' => 'none',
        'criminal_record_status' => 'valid',
        'management_level' => 0,
    ];
}
