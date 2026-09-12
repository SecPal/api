<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses()->group('serial');

function refreshTransactionalEditConcurrencyDatabase(): void
{
    Artisan::call('migrate:fresh', ['--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

beforeEach(function (): void {
    refreshTransactionalEditConcurrencyDatabase();
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    cleanupTestKekFile();
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.read');
    givePermissionWithTenant($this->user, $this->tenant->id, 'customers.update');
    $this->token = $this->user->createToken('transactional-race')->plainTextToken;

    $legalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Race Baseline',
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    refreshTransactionalEditConcurrencyDatabase();
    RefreshDatabaseState::$migrated = false;
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('two HTTP edits from one validator cannot both commit', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for transactional edit concurrency evidence.');
    }

    $etag = $this->withToken($this->token)
        ->getJson("/v1/customers/{$this->customer->id}")
        ->assertOk()
        ->headers->get('ETag');
    expect($etag)->toBeString();

    $directory = sys_get_temp_dir().'/transactional-edit-race-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create transactional edit race directory.');
    }

    $release = $directory.'/release';
    $childPids = [];
    DB::disconnect();

    try {
        $blockerPid = pcntl_fork();
        if ($blockerPid === -1) {
            throw new RuntimeException('Unable to fork aggregate-lock holder.');
        }

        if ($blockerPid === 0) {
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();
            DB::statement(<<<'SQL'
                LOCK TABLE customers, customer_establishments, sites, customer_assignments, users
                IN SHARE ROW EXCLUSIVE MODE
                SQL);
            file_put_contents($directory.'/locked', 'yes');
            while (! is_file($release)) {
                usleep(25_000);
            }
            DB::commit();
            exit(0);
        }
        $childPids[] = $blockerPid;

        $deadline = microtime(true) + 10;
        while (! is_file($directory.'/locked') && microtime(true) < $deadline) {
            usleep(25_000);
        }
        expect(is_file($directory.'/locked'))->toBeTrue();

        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork transactional edit worker.');
            }

            if ($pid === 0) {
                if (function_exists('xdebug_stop_code_coverage')) {
                    xdebug_stop_code_coverage(false);
                }

                DB::purge();
                DB::reconnect();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
                file_put_contents(
                    $directory."/pid-{$worker}",
                    (string) DB::scalar('SELECT pg_backend_pid()'),
                );

                $response = $this->withToken($this->token)
                    ->withHeader('If-Match', $etag)
                    ->putJson("/v1/customers/{$this->customer->id}/transactional-edit", [
                        'customer' => ['name' => "Concurrent Winner {$worker}"],
                        'customer_establishments' => [],
                    ]);

                file_put_contents(
                    $directory."/result-{$worker}",
                    json_encode([
                        'status' => $response->getStatusCode(),
                        'body' => $response->json(),
                    ], JSON_THROW_ON_ERROR),
                );
                exit(0);
            }

            $childPids[] = $pid;
        }

        while ((! is_file($directory.'/pid-1') || ! is_file($directory.'/pid-2'))
            && microtime(true) < $deadline) {
            usleep(25_000);
        }

        DB::purge();
        DB::reconnect();
        $workerPids = [
            (int) file_get_contents($directory.'/pid-1'),
            (int) file_get_contents($directory.'/pid-2'),
        ];
        $bothBlocked = false;
        while (microtime(true) < $deadline) {
            $bothBlocked = DB::table('pg_stat_activity')
                ->whereIn('pid', $workerPids)
                ->where('wait_event_type', 'Lock')
                ->count() === 2;
            if ($bothBlocked) {
                break;
            }
            usleep(25_000);
        }

        expect($bothBlocked)->toBeTrue();
        file_put_contents($release, 'go');

        foreach ($childPids as $pid) {
            expect(pcntl_waitpid($pid, $status))->toBe($pid)
                ->and(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $childPids = [];

        DB::purge();
        DB::reconnect();
        $results = collect([1, 2])->map(
            fn (int $worker): array => json_decode(
                (string) file_get_contents($directory."/result-{$worker}"),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        expect($results->pluck('status')->sort()->values()->all())->toBe([200, 412])
            ->and($results->firstWhere('status', 412)['body'])->toBe([
                'message' => 'The customer edit snapshot is stale.',
                'code' => 'CUSTOMER_EDIT_STALE',
            ])
            ->and($this->customer->refresh()->name)
            ->toBeIn(['Concurrent Winner 1', 'Concurrent Winner 2']);
    } finally {
        if (! is_file($release)) {
            file_put_contents($release, 'go');
        }
        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
        DB::purge();
        DB::reconnect();
    }
});

test('a site visibility change after the initial validator check prevents commit', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for transactional edit concurrency evidence.');
    }

    $establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->customer->legal_entity_id,
    ]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'legal_entity_id' => $this->customer->legal_entity_id,
        'establishment_id' => $establishment->id,
        'access_instructions' => 'Visible only to site editors',
        'notes' => 'Policy-dependent representation',
    ]);

    $initial = $this->withToken($this->token)
        ->getJson("/v1/customers/{$this->customer->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.sites.0.access_instructions')
        ->assertJsonMissingPath('data.sites.0.notes');
    $etag = $initial->headers->get('ETag');
    expect($etag)->toBeString();

    $directory = sys_get_temp_dir().'/transactional-site-visibility-race-'.bin2hex(random_bytes(8));
    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create site visibility race directory.');
    }

    $release = $directory.'/release';
    $childPids = [];
    DB::disconnect();

    try {
        $blockerPid = pcntl_fork();
        if ($blockerPid === -1) {
            throw new RuntimeException('Unable to fork establishment-lock holder.');
        }

        if ($blockerPid === 0) {
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();
            DB::table('establishments')
                ->where('id', $establishment->id)
                ->lockForUpdate()
                ->first();
            file_put_contents($directory.'/locked', 'yes');
            while (! is_file($release)) {
                usleep(25_000);
            }
            DB::commit();
            exit(0);
        }
        $childPids[] = $blockerPid;

        $deadline = microtime(true) + 10;
        while (! is_file($directory.'/locked') && microtime(true) < $deadline) {
            usleep(25_000);
        }
        expect(is_file($directory.'/locked'))->toBeTrue();

        $workerPid = pcntl_fork();
        if ($workerPid === -1) {
            throw new RuntimeException('Unable to fork transactional edit worker.');
        }

        if ($workerPid === 0) {
            if (function_exists('xdebug_stop_code_coverage')) {
                xdebug_stop_code_coverage(false);
            }

            DB::purge();
            DB::reconnect();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
            file_put_contents(
                $directory.'/worker-pid',
                (string) DB::scalar('SELECT pg_backend_pid()'),
            );

            $response = $this->withToken($this->token)
                ->withHeader('If-Match', $etag)
                ->putJson("/v1/customers/{$this->customer->id}/transactional-edit", [
                    'customer' => ['name' => 'Must Not Commit'],
                    'customer_establishments' => [[
                        'customer_id' => $this->customer->id,
                        'establishment_id' => $establishment->id,
                    ]],
                ]);

            file_put_contents(
                $directory.'/result',
                json_encode([
                    'status' => $response->getStatusCode(),
                    'body' => $response->json(),
                ], JSON_THROW_ON_ERROR),
            );
            exit(0);
        }
        $childPids[] = $workerPid;

        while (! is_file($directory.'/worker-pid') && microtime(true) < $deadline) {
            usleep(25_000);
        }
        DB::purge();
        DB::reconnect();
        $workerBackendPid = (int) file_get_contents($directory.'/worker-pid');
        $workerReachedPostEtagValidation = false;
        while (microtime(true) < $deadline) {
            $workerReachedPostEtagValidation = DB::table('pg_stat_activity')
                ->where('pid', $workerBackendPid)
                ->where('wait_event_type', 'Lock')
                ->exists();
            if ($workerReachedPostEtagValidation) {
                break;
            }
            usleep(25_000);
        }
        expect($workerReachedPostEtagValidation)->toBeTrue();

        SiteAssignment::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'role' => 'Site Manager',
        ]);
        $changed = $this->withToken($this->token)
            ->getJson("/v1/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('data.sites.0.access_instructions', 'Visible only to site editors')
            ->assertJsonPath('data.sites.0.notes', 'Policy-dependent representation');
        expect($changed->headers->get('ETag'))->not->toBe($etag);

        file_put_contents($release, 'go');
        foreach ($childPids as $pid) {
            expect(pcntl_waitpid($pid, $status))->toBe($pid)
                ->and(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $childPids = [];

        $result = json_decode(
            (string) file_get_contents($directory.'/result'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect($result)->toBe([
            'status' => 412,
            'body' => [
                'message' => 'The customer edit snapshot is stale.',
                'code' => 'CUSTOMER_EDIT_STALE',
            ],
        ])->and($this->customer->refresh()->name)->toBe('Race Baseline');
    } finally {
        if (! is_file($release)) {
            file_put_contents($release, 'go');
        }
        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
        DB::purge();
        DB::reconnect();
    }
});
