<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

it('lists scheduled tasks without a database connection', function (): void {
    expect(Illuminate\Support\Facades\Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

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
    $artifacts = ['Dockerfile', '.dockerignore', 'docker/frankenphp/Caddyfile', 'docker/php/conf.d/production.ini',
        'docker/python/opentimestamps-requirements.txt', 'docker/healthchecks/http-live.sh', 'tests/docker/smoke.sh',
        '.github/workflows/container-image.yml', 'config/trustedproxy.php'];
    foreach ($artifacts as $path) {
        expect(is_file($root.'/'.$path))->toBeTrue("Missing container artifact: {$path}");
    }

    $dockerfile = file_get_contents($root.'/Dockerfile');
    $caddyfile = file_get_contents($root.'/docker/frankenphp/Caddyfile');
    $productionIni = file_get_contents($root.'/docker/php/conf.d/production.ini');
    $workflow = file_get_contents($root.'/.github/workflows/container-image.yml');
    $documentation = file_get_contents($root.'/docs/containers.md');
    $proxyConfig = file_get_contents($root.'/config/trustedproxy.php');

    expect($dockerfile)
        ->toContain('dunglas/frankenphp:1.12.6-php8.4.23-bookworm@sha256:')
        ->toContain('composer install')
        ->toContain('--no-dev')
        ->toContain('--no-scripts')
        ->toContain('--require-hashes', '--only-binary=:all:', 'opentimestamps-requirements.txt')
        ->toContain('rm -f bootstrap/cache/*.php', '/config/caddy /config/psysh')
        ->toContain('redis-6.3.0')
        ->toContain('USER secpal')
        ->toContain('EXPOSE 8080')
        ->toContain('HEALTHCHECK NONE')
        ->not->toContain('artisan migrate')
        ->not->toContain('octane');

    expect($caddyfile)
        ->toContain('admin off', 'auto_https off', ':8080')
        ->toContain('root * /app/public')
        ->toContain('php_server')
        ->toContain('request>uri query', 'replace token REDACTED', 'replace email REDACTED')
        ->toContain('/.htaccess', '/*.license')
        ->not->toContain('worker');

    expect($productionIni)->toContain('upload_max_filesize=10M', 'post_max_size=12M');
    expect($workflow)->toContain('"storage/**"', '"artisan"', '"LICENSE"', '"THIRD-PARTY-NOTICES.md"', '"LICENSES/**"');
    expect($documentation)->toContain('/app/storage/app/private')->toContain('persistent');
    expect($proxyConfig)->toContain('TRUSTED_PROXIES');
});
