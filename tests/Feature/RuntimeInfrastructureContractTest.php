<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Symfony\Component\Process\Process;

test('active runtime configuration exposes only the supported adapters and bounded helpers', function (): void {
    $exampleEnvironment = (string) file_get_contents(base_path('.env.example'));

    expect(config('database.default'))->toBe('pgsql')
        ->and(array_keys(config('database.connections')))->toBe(['sqlite', 'pgsql'])
        ->and(config('database.connections.pgsql.url'))->toBeNull()
        ->and(config('database.connections.pgsql'))->not->toHaveKey('channel_binding')
        ->and(array_keys(config('cache.stores')))->toBe(['array', 'database', 'file'])
        ->and(config('cache.stores.database.lock_connection'))->toBe('pgsql')
        ->and(config('cache.stores.database.lock_table'))->toBe('cache_locks')
        ->and(array_keys(config('queue.connections')))->toBe(['sync', 'database'])
        ->and(config('queue.connections.database.after_commit'))->toBeTrue()
        ->and(config('queue.batching.database'))->toBe('pgsql')
        ->and(config('queue.failed.database'))->toBe('pgsql')
        ->and(config('session.driver'))->toBe('database')
        ->and(config('session'))->not->toHaveKeys(['files', 'store'])
        ->and(config('database'))->not->toHaveKey('redis')
        ->and(config('filesystems.disks'))->toHaveKey('s3')
        ->and($exampleEnvironment)->toContain(
            'DB_CONNECTION=pgsql',
            'SESSION_DRIVER=database',
            'QUEUE_CONNECTION=database',
            'CACHE_STORE=database',
            'DB_SSLMODE=prefer',
            '# DB_SSLROOTCERT=/run/secrets/postgresql-ca.crt',
        )
        ->and($exampleEnvironment)->not->toMatch('/^(?:REDIS|MEMCACHED|SQS|DYNAMODB|BEANSTALKD)_/m');
});

test('production boot rejects unsupported runtime infrastructure', function (array $environment, string $configuration): void {
    $process = new Process(
        [PHP_BINARY, 'artisan', 'about', '--only=environment'],
        base_path(),
        array_merge(productionRuntimeEnvironment(), $environment),
    );
    $process->setTimeout(10)->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain("Unsupported production {$configuration}");
})->with([
    'relational database' => [['DB_CONNECTION' => 'mysql'], 'database connection [mysql]'],
    'cache store' => [['CACHE_STORE' => 'redis'], 'cache store [redis]'],
    'queue connection' => [['QUEUE_CONNECTION' => 'sqs'], 'queue connection [sqs]'],
    'session driver' => [['SESSION_DRIVER' => 'file'], 'session driver [file]'],
]);

test('production boot requires verified PostgreSQL server identity', function (array $environment, string $message): void {
    $process = new Process(
        [PHP_BINARY, 'artisan', 'about', '--only=environment'],
        base_path(),
        array_merge(productionRuntimeEnvironment(), $environment),
    );
    $process->setTimeout(10)->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())->toContain($message);
})->with([
    'TLS identity verification' => [['DB_SSLMODE' => 'prefer'], 'DB_SSLMODE=verify-full'],
    'trusted CA input' => [['DB_SSLROOTCERT' => ''], 'DB_SSLROOTCERT'],
]);

test('production package discovery works before deployment trust material is mounted', function (): void {
    $process = new Process(
        [PHP_BINARY, 'artisan', 'package:discover', '--ansi'],
        base_path(),
        array_merge(productionRuntimeEnvironment(), [
            'DB_SSLMODE' => 'prefer',
            'DB_SSLROOTCERT' => '',
        ]),
    );
    $process->setTimeout(20)->run();

    expect($process->isSuccessful())->toBeTrue();
});

test('production static tooling can bootstrap without deployment trust material', function (): void {
    $script = <<<'PHP'
    $basePath = $argv[1];
    $_SERVER['argv'] = [$basePath.'/vendor/bin/phpstan'];
    require $basePath.'/vendor/autoload.php';
    $application = require $basePath.'/bootstrap/app.php';
    $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    PHP;

    $process = new Process(
        [PHP_BINARY, '-r', $script, base_path()],
        base_path(),
        array_merge(productionRuntimeEnvironment(), [
            'DB_SSLMODE' => 'prefer',
            'DB_SSLROOTCERT' => '',
        ]),
    );
    $process->setTimeout(20)->run();

    expect($process->isSuccessful())->toBeTrue();
});

/**
 * @return array<string, string>
 */
function productionRuntimeEnvironment(): array
{
    return [
        'APP_ENV' => 'production',
        'CACHE_STORE' => 'database',
        'DB_CONNECTION' => 'pgsql',
        'DB_SSLMODE' => 'verify-full',
        'DB_SSLROOTCERT' => '/run/secrets/postgresql-ca.crt',
        'QUEUE_CONNECTION' => 'database',
        'SESSION_DRIVER' => 'database',
    ];
}
