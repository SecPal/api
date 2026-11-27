<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Services\GlobalIdentity\FileGlobalIdentityRootProvider;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class, Tests\Support\ManagesGlobalIdentityFixture::class)->group('serial');

it('serializes competing bootstraps and delays activation until the active writer commits', function (): void {
    $directory = sys_get_temp_dir().'/secpal-key-concurrency-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    (new FileGlobalIdentityRootProvider($directory))->provision(new GlobalIdentityKeyReference('root', 1));
    config(['global_identity.root_directory' => $directory]);

    $initializers = [registryWorker($directory), registryWorker($directory)];
    $activators = [];
    try {
        foreach ($initializers as $worker) {
            $worker->start();
        }
        foreach ($initializers as $worker) {
            expect($worker->wait())->toBe(0);
        }

        $rows = DB::table('global_identity_working_keys');
        expect((clone $rows)->count())->toBe(count(Purpose::cases()));
        $registry = app(GlobalIdentityWorkingKeyRegistry::class);
        $purpose = Purpose::EmailEncryption;
        $candidate = $registry->stage($purpose);
        $activators = [registryWorker($directory, $candidate), registryWorker($directory, $candidate)];

        $registry->withActiveKey($purpose, function (
            #[SensitiveParameter] string $key,
            GlobalIdentityWorkingKeyContext $active,
        ) use ($activators): void {
            expect($active->reference->version)->toBe(1);
            foreach ($activators as $worker) {
                $worker->start();
            }
            foreach ($activators as $worker) {
                $worker->waitUntil(static fn (string $type, string $output): bool => preg_match('/^[0-9]+\n$/', $output) === 1);
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
        });

        foreach ($activators as $worker) {
            expect($worker->wait())->toBe(0);
        }
        expect($rows->where('purpose', $purpose->value)->orderBy('key_version')->pluck('state')->all())
            ->toBe(['decrypt-only', 'write-active']);
    } finally {
        foreach ([...$initializers, ...$activators] as $worker) {
            $worker->stop();
        }
        (new Filesystem)->deleteDirectory($directory);
        DB::table('global_identity_working_keys')->delete();
    }
});

function registryWorker(string $directory, ?GlobalIdentityWorkingKeyContext $candidate = null): Process
{
    $script = <<<'WORKER'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    $db->statement("SET statement_timeout = '10s'");
    echo $db->scalar('SELECT pg_backend_pid()').PHP_EOL;
    $registry = $app->make(App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry::class);
    if ($argv[1] === '') {
        $registry->initialize(new App\Services\GlobalIdentity\GlobalIdentityKeyReference('root', 1));
    } else {
        $registry->activate(new App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext(
            App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose::EmailEncryption,
            new App\Services\GlobalIdentity\GlobalIdentityKeyReference($argv[1], (int) $argv[2]),
        ));
    }
    WORKER;

    return new Process(
        [PHP_BINARY, '-r', $script, $candidate?->reference->id ?? '', (string) $candidate?->reference->version],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_KEY' => config()->string('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'GLOBAL_IDENTITY_ROOT_DIRECTORY' => $directory,
            'SECPAL_TEST_DATABASE' => config()->string('database.connections.pgsql.database'),
            'SECPAL_TEST_SCHEMA' => (string) DB::scalar('SELECT current_schema()'),
        ],
        timeout: 15,
    );
}
