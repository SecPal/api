<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
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
function ensureParallelWorkerDatabaseForSiteNumberConcurrency(): void
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

function refreshCustomerSiteNumberConcurrencyDatabase(): void
{
    ensureParallelWorkerDatabaseForSiteNumberConcurrency();

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property Customer $customer
 * @property OrganizationalUnit $organizationalUnit
 */
beforeEach(function (): void {
    refreshCustomerSiteNumberConcurrencyDatabase();

    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create();
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.create');
    givePermissionWithTenant($this->user, $this->tenant->id, 'sites.create');
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshCustomerSiteNumberConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('concurrent customer creation requests produce distinct customer numbers', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the customer number concurrency regression test.');
    }

    $requestCount = 8;

    $results = runConcurrentJsonPosts(
        $this,
        $requestCount,
        '/v1/customers',
        fn (int $index): array => customerCreationPayload($index),
        fn (array $body): array => [
            'status' => $body['status'],
            'customer_number' => $body['body']['data']['customer_number'] ?? null,
        ],
    );

    expect($results->pluck('status')->all())->toBe(array_fill(0, $requestCount, 201));

    /** @var array<int, string> $customerNumbers */
    $customerNumbers = $results->pluck('customer_number')->all();

    expect(count(array_unique($customerNumbers)))->toBe($requestCount);
    expect(Customer::query()->where('tenant_id', $this->tenant->id)->count())->toBe($requestCount + 1);
});

test('concurrent site creation requests produce distinct site numbers', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the site number concurrency regression test.');
    }

    $requestCount = 8;

    $results = runConcurrentJsonPosts(
        $this,
        $requestCount,
        '/v1/sites',
        fn (int $index): array => siteCreationPayload($this->customer->id, $this->organizationalUnit->id, $index),
        fn (array $body): array => [
            'status' => $body['status'],
            'site_number' => $body['body']['data']['site_number'] ?? null,
        ],
    );

    expect($results->pluck('status')->all())->toBe(array_fill(0, $requestCount, 201));

    /** @var array<int, string> $siteNumbers */
    $siteNumbers = $results->pluck('site_number')->all();

    expect(count(array_unique($siteNumbers)))->toBe($requestCount);
    expect(Site::query()->where('tenant_id', $this->tenant->id)->count())->toBe($requestCount);
});

/**
 * @param  Closure(int): array<string, mixed>  $payloadFactory
 * @param  Closure(array{status: int, body: array<string, mixed>|null}): array<string, int|string|null>  $resultFactory
 * @return Illuminate\Support\Collection<int, array<string, int|string|null>>
 */
function runConcurrentJsonPosts(
    Tests\TestCase $testCase,
    int $requestCount,
    string $uri,
    Closure $payloadFactory,
    Closure $resultFactory,
): Illuminate\Support\Collection {
    $synchronizationDirectory = sys_get_temp_dir().'/number-concurrency-'.uniqid('', true);

    if (! mkdir($synchronizationDirectory) && ! is_dir($synchronizationDirectory)) {
        throw new RuntimeException('Unable to create synchronization directory for concurrency test.');
    }

    $startSignalPath = $synchronizationDirectory.'/start.signal';
    file_put_contents($startSignalPath, 'wait');

    try {
        $childPids = [];

        for ($index = 1; $index <= $requestCount; $index++) {
            $resultPath = $synchronizationDirectory."/result-{$index}.json";
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork concurrency test child process.');
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

                $response = $testCase->withToken($testCase->token)
                    ->postJson($uri, $payloadFactory($index));

                file_put_contents($resultPath, json_encode([
                    'status' => $response->getStatusCode(),
                    'body' => $response->json(),
                ], JSON_THROW_ON_ERROR));

                exit(0);
            }

            $childPids[] = $pid;
        }

        file_put_contents($startSignalPath, 'go');

        foreach ($childPids as $childPid) {
            expect(pcntl_waitpid($childPid, $status))->toBe($childPid);
            expect(pcntl_wifexited($status))->toBeTrue();
            expect(pcntl_wifsignaled($status))->toBeFalse();
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return collect(range(1, $requestCount))
            ->map(function (int $index) use ($resultFactory, $synchronizationDirectory): array {
                /** @var array{status: int, body: array<string, mixed>|null} $result */
                $result = json_decode(
                    (string) file_get_contents($synchronizationDirectory."/result-{$index}.json"),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                return $resultFactory($result);
            });
    } finally {
        if (file_exists($startSignalPath)) {
            unlink($startSignalPath);
        }

        foreach (range(1, $requestCount) as $index) {
            $path = $synchronizationDirectory."/result-{$index}.json";
            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (is_dir($synchronizationDirectory)) {
            rmdir($synchronizationDirectory);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function customerCreationPayload(int $index): array
{
    return [
        'name' => "Concurrent Customer {$index}",
        'billing_address' => [
            'street' => "Concurrency Street {$index}",
            'city' => 'Berlin',
            'postal_code' => sprintf('10%03d', $index),
            'country' => 'DE',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function siteCreationPayload(string $customerId, string $organizationalUnitId, int $index): array
{
    return [
        'name' => "Concurrent Site {$index}",
        'customer_id' => $customerId,
        'organizational_unit_id' => $organizationalUnitId,
        'type' => 'permanent',
        'address' => [
            'street' => "Object Street {$index}",
            'city' => 'Berlin',
            'postal_code' => sprintf('12%03d', $index),
            'country' => 'DE',
        ],
    ];
}
