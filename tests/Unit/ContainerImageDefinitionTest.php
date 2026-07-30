<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

it('lists scheduled tasks without a database connection', function (): void {
    $process = new Symfony\Component\Process\Process(
        [PHP_BINARY, 'artisan', 'schedule:list', '--json'], base_path(),
        ['APP_ENV' => 'production', 'CACHE_STORE' => 'database', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'unreachable.invalid'],
    );
    $process->setTimeout(10)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true))->toBeArray()->not->toBeEmpty();
});

it('defines the production API image contract', function (): void {
    $root = dirname(__DIR__, 2);

    $artifacts = ['Dockerfile', '.dockerignore', 'docker/frankenphp/Caddyfile',
        'docker/php/conf.d/production.ini', 'docker/healthchecks/http-live.sh',
        'tests/docker/smoke.sh', '.github/workflows/container-image.yml'];

    foreach ($artifacts as $path) {
        expect(is_file($root.'/'.$path))->toBeTrue("Missing container artifact: {$path}");
    }

    $dockerfile = file_get_contents($root.'/Dockerfile');
    $caddyfile = file_get_contents($root.'/docker/frankenphp/Caddyfile');

    expect($dockerfile)
        ->toContain('dunglas/frankenphp:1.12.6-php8.4.23-bookworm@sha256:')
        ->toContain('composer install')
        ->toContain('--no-dev')
        ->toContain('--no-scripts')
        ->toContain('redis-6.3.0')
        ->toContain('USER secpal')
        ->toContain('EXPOSE 8080')
        ->not->toContain('HEALTHCHECK')
        ->not->toContain('artisan migrate')
        ->not->toContain('octane');

    expect($caddyfile)
        ->toContain('admin off', 'auto_https off', ':8080')
        ->toContain('root * /app/public')
        ->toContain('php_server')
        ->not->toContain('worker');
});
