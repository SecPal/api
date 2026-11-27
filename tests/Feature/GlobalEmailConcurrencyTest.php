<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\GlobalIdentityKeyException;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalEmailLookupVersions;
use App\Services\GlobalIdentity\GlobalEmailRepository;
use App\Services\GlobalIdentity\GlobalEmailSchema;
use App\Services\GlobalIdentity\GlobalIdentityAccounts;
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

it('seeds encrypted identities after ordinary migrations and explicit bootstrap', function (): void {
    config(['address_data.import_on_setup' => false]);
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
    $directory = sys_get_temp_dir().'/secpal-seed-root-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    try {
        $root = new GlobalIdentityKeyReference('root', 1);
        (new FileGlobalIdentityRootProvider($directory))->provision($root);
        config(['global_identity.root_directory' => $directory]);
        expect(Artisan::call('identity:bootstrap', ['root-id' => 'root', 'root-version' => '1', '--force' => true]))->toBe(0)
            ->and(Artisan::call('db:seed', ['--force' => true]))->toBe(0)
            ->and(Schema::hasColumn('users', 'email'))->toBeFalse()
            ->and(DB::table('users')->whereNotNull('email_idx')->count())->toBeGreaterThan(0);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('serializes concurrent user creation and reservations under two accepted key versions', function (): void {
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
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

        $cancelled = (string) Str::uuid();
        $release = globalEmailWorker($directory, $this->schema, $tenantId, 'release', $cancelled);
        $workers[] = $release;
        DB::transaction(function () use ($repository, $versions, $cancelled, $release): void {
            $repository->reserve($cancelled, 'cancelled@secpal.app', $versions);
            assertEmailWorkerBlocked($release);
        });
        expect($release->wait())->toBe(0)
            ->and(DB::table(GlobalEmailSchema::RESERVATIONS)->where('operation_id', $cancelled)->exists())->toBeFalse();

        $userId = $repository->findUserId('first@secpal.app', $versions);
        expect($userId)->toBeString();
        $change = (string) Str::uuid();
        $repository->reserve($change, 'next@secpal.app', $versions);
        $activate = globalEmailWorker($directory, $this->schema, $tenantId, 'activate', 'next@secpal.app', [$userId, $change]);
        $workers[] = $activate;
        expect(fn () => $repository->checkEmailVersionForDispatch($userId, 1))->toThrow(GlobalIdentityKeyException::class);
        DB::transaction(function () use ($repository, $userId, $activate): void {
            expect($repository->checkEmailVersionForDispatch($userId, 1))->toBeTrue();
            assertEmailWorkerBlocked($activate);
            expect($repository->checkEmailVersionForDispatch($userId, 1))->toBeTrue();
        });
        expect($activate->wait())->toBe(0);
        expect(DB::transaction(fn () => $repository->checkEmailVersionForDispatch($userId, 1)))->toBeFalse();
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('serializes lookup activation and account writes against the rotation projections', function (): void {
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
    $directory = sys_get_temp_dir().'/secpal-racing-rotation-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $workers = [];
    try {
        $root = new GlobalIdentityKeyReference('root', 1);
        (new FileGlobalIdentityRootProvider($directory))->provision($root);
        config(['global_identity.root_directory' => $directory]);
        $keys = app(GlobalIdentityWorkingKeyRegistry::class);
        $keys->initialize($root);
        $tenant = TenantKey::factory()->create();
        $accounts = app(GlobalIdentityAccounts::class);
        $user = $accounts->create(new User(['name' => 'Fixture', 'password' => 'test-password', 'tenant_id' => $tenant->id]), 'Old@secpal.app');
        $worker = globalEmailWorker($directory, $this->schema, $tenant->id, 'rotate_lookup', 'unused');
        $workers[] = $worker;
        $keys->withLookupVersions(function (GlobalEmailLookupVersions $versions) use ($worker, $accounts, $user): void {
            expect($versions->accepted)->toBe([1]);
            assertEmailWorkerBlocked($worker);
            expect($accounts->find('old@secpal.app')?->id)->toBe($user->id);
        });
        expect($worker->wait())->toBe(0);

        $repo = app(GlobalEmailRepository::class);
        $competitor = globalEmailWorker($directory, $this->schema, $tenant->id, 'create_active', 'old@secpal.app');
        $workers[] = $competitor;
        DB::transaction(function () use ($repo, $user, $competitor): void {
            expect($repo->rotateIndex($user->id, promote: false))->toBeTrue();
            assertEmailWorkerBlocked($competitor);
        });
        expect($competitor->wait())->toBe(0)
            ->and(DB::table('users')->count())->toBe(1)
            ->and($repo->rotateIndex($user->id, promote: true))->toBeTrue()
            ->and($accounts->find('old@secpal.app')?->id)->toBe($user->id);
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

/** @param list<string> $arguments */
function globalEmailWorker(string $root, string $schema, int $tenantId, string $operation, string $email, array $arguments = []): Process
{
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $db->statement("SET statement_timeout = '10s'");
        echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;
        $repository = $app->make(App\Services\GlobalIdentity\GlobalEmailRepository::class);
        if ($argv[2] === 'rotate_lookup') {
            $keys = $app->make(App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry::class);
            $keys->activateLookupRotation($keys->stage(App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose::UserEmailLookup));
            exit(0);
        }
        if ($argv[2] === 'release') {
            $repository->release($argv[3]);
            exit(0);
        }
        $versions = new App\Services\GlobalIdentity\GlobalEmailLookupVersions(2, [2, 1]);
        if ($argv[2] === 'activate') {
            $repository->activate($argv[4], 1, $argv[5], $argv[3], $versions);
            exit(0);
        }
        try {
            if ($argv[2] === 'create_active') {
                $user = new App\Models\User(['name' => 'Fixture', 'password' => 'test-password', 'tenant_id' => (int) $argv[1]]);
                $app->make(App\Services\GlobalIdentity\GlobalIdentityAccounts::class)->create($user, $argv[3]);
            } elseif ($argv[2] === 'create') {
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
        [PHP_BINARY, '-r', $code, (string) $tenantId, $operation, $email, ...$arguments],
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
