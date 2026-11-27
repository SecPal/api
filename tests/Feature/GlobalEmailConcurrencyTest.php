<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\TenantKey;
use App\Models\User;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalEmailLookupVersions;
use App\Services\GlobalIdentity\GlobalEmailRepository;
use App\Services\GlobalIdentity\GlobalEmailSchema;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses()->group('serial');

beforeEach(function (): void {
    $this->schema = 'global_email_'.bin2hex(random_bytes(8));
    $this->originalSearchPath = config('database.connections.pgsql.search_path');
    DB::statement('CREATE SCHEMA '.$this->schema);
    config(['database.connections.pgsql.search_path' => $this->schema]);
    DB::purge();
});

afterEach(function (): void {
    DB::purge();
    config(['database.connections.pgsql.search_path' => $this->originalSearchPath]);
    DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
});

it('runs the ordinary clean migrations and seeders before the identity cutover', function (): void {
    config(['address_data.import_on_setup' => false]);
    expect(Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]))->toBe(0);
    expect(Schema::hasColumn('users', 'email'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email_idx'))->toBeFalse()
        ->and(DB::table('users')->whereNotNull('email')->count())->toBeGreaterThan(0);
});

it('serializes concurrent user creation and reservations under two accepted key versions', function (): void {
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
    (new GlobalEmailSchema)->apply();
    $directory = sys_get_temp_dir().'/secpal-email-workers-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $workers = [];
    try {
        $root = new GlobalIdentityKeyReference('root', 1);
        (new FileGlobalIdentityRootProvider($directory))->provision($root);
        config(['global_identity.root_directory' => $directory]);
        $keys = app(GlobalIdentityWorkingKeyRegistry::class);
        $keys->initialize($root);
        $keys->activate($keys->stage(Purpose::UserEmailLookup));
        $repository = app(GlobalEmailRepository::class);
        $versions = new GlobalEmailLookupVersions(2, [1, 2]);
        $tenantId = TenantKey::factory()->create()->id;

        $create = globalEmailWorker($directory, $this->schema, $tenantId, 'create', 'first@secpal.app');
        $workers[] = $create;
        DB::transaction(function () use ($repository, $versions, $tenantId, $create): void {
            $repository->create(new User(['name' => 'Fixture', 'password' => 'test-password', 'tenant_id' => $tenantId]), 'First@secpal.app', $versions);
            assertEmailWorkerBlocked($create);
        });
        expect($create->wait())->toBe(0);
        expect(DB::table('users')->count())->toBe(1);

        $operation = (string) Str::uuid();
        $otherCreate = globalEmailWorker($directory, $this->schema, $tenantId, 'create', 'reserved@secpal.app');
        $otherReserve = globalEmailWorker($directory, $this->schema, $tenantId, 'reserve', 'reserved@secpal.app');
        $workers[] = $otherCreate;
        $workers[] = $otherReserve;
        DB::transaction(function () use ($repository, $versions, $operation, $otherCreate, $otherReserve): void {
            $repository->reserve($operation, 'Reserved@secpal.app', $versions);
            assertEmailWorkerBlocked($otherCreate);
            assertEmailWorkerBlocked($otherReserve);
        });
        expect($otherCreate->wait())->toBe(0)
            ->and($otherReserve->wait())->toBe(0)
            ->and(DB::table('users')->count())->toBe(1)
            ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->pluck('operation_id')->all())->toBe([$operation, $operation])
            ->and(DB::table(GlobalEmailSchema::ROTATION_INDEXES)->pluck('index_key_version')->all())->toBe([1]);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});

function assertEmailWorkerBlocked(Process $worker): void
{
    $worker->start();
    $ready = $worker->waitUntil(static fn (string $type, string $text): bool => $type === Process::OUT && preg_match('/^\d+\n$/', $text) === 1);
    expect($ready)->toBeTrue();
    $pid = (int) trim($worker->getOutput());
    expect($pid)->toBeGreaterThan(0);
    $deadline = microtime(true) + 5;
    do {
        $blocked = (bool) DB::scalar('SELECT cardinality(pg_blocking_pids(?)) > 0', [$pid]);
        if (! $blocked) {
            usleep(10_000);
        }
    } while (! $blocked && microtime(true) < $deadline);
    expect($blocked)->toBeTrue();
}

function globalEmailWorker(string $root, string $schema, int $tenantId, string $operation, string $email): Process
{
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement("SET statement_timeout = '10s'");
        echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;
        $repository = $app->make(App\Services\GlobalIdentity\GlobalEmailRepository::class);
        $versions = new App\Services\GlobalIdentity\GlobalEmailLookupVersions(2, [2, 1]);
        try {
            if ($argv[2] === 'create') {
                $user = new App\Models\User(['name' => 'Fixture', 'password' => 'test-password', 'tenant_id' => (int) $argv[1]]);
                $repository->create($user, $argv[3], $versions);
            } else {
                $repository->reserve((string) Illuminate\Support\Str::uuid(), $argv[3], $versions);
            }
        } catch (App\Exceptions\GlobalIdentityKeyException $error) {
            exit($error->getMessage() === 'Global email address unavailable.' ? 0 : 2);
        }
        exit(1);
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $code, (string) $tenantId, $operation, $email],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => config()->string('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'GLOBAL_IDENTITY_ROOT_DIRECTORY' => $root,
            'SECPAL_TEST_DATABASE' => config()->string('database.connections.pgsql.database'),
            'SECPAL_TEST_SCHEMA' => $schema,
        ],
        timeout: 18,
    );
}
