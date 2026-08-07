<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

it('defines the production API image contract', function (): void {
    $root = dirname(__DIR__, 2);
    $artifacts = ['Dockerfile', '.dockerignore', 'docker/frankenphp/Caddyfile', 'docker/php/conf.d/production.ini',
        'docker/python/opentimestamps-requirements.txt', 'docker/healthchecks/http-live.sh', 'tests/docker/smoke.sh',
        'tests/docker/assert-port-closed.php', '.github/workflows/container-image.yml', 'config/trustedproxy.php'];
    foreach ($artifacts as $path) {
        expect(is_file($root.'/'.$path))->toBeTrue("Missing container artifact: {$path}");
    }

    $dockerfile = file_get_contents($root.'/Dockerfile');
    $dockerignore = file_get_contents($root.'/.dockerignore');
    $caddyfile = file_get_contents($root.'/docker/frankenphp/Caddyfile');
    $productionIni = file_get_contents($root.'/docker/php/conf.d/production.ini');
    $workflow = file_get_contents($root.'/.github/workflows/container-image.yml');
    $documentation = file_get_contents($root.'/docs/containers.md');
    $proxyConfig = file_get_contents($root.'/config/trustedproxy.php');
    $smokeScript = file_get_contents($root.'/tests/docker/smoke.sh');

    expect($dockerfile)
        ->toContain('dunglas/frankenphp:1.12.6-php8.4.23-bookworm@sha256:')
        ->toContain('composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760')
        ->toContain('composer install')
        ->toContain('--no-dev')
        ->toContain('--no-scripts')
        ->toContain('--require-hashes', '--only-binary=:all:', 'opentimestamps-requirements.txt')
        ->toContain('rm -f bootstrap/cache/*.php', '/config/caddy /config/psysh')
        ->toContain('redis-6.3.0')
        ->toContain('COPY --chown=root:root --chmod=0644 docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile')
        ->toContain('COPY --chown=root:root --chmod=0644 docker/php/conf.d/production.ini /usr/local/etc/php/conf.d/zz-secpal-production.ini')
        ->toContain('COPY --chown=root:root --chmod=0755 docker/healthchecks/http-live.sh /usr/local/bin/secpal-http-live')
        ->toContain('chmod 0644 /etc/frankenphp/Caddyfile')
        ->toContain('chmod 0644 "${PHP_INI_DIR}/conf.d/zz-secpal-production.ini"')
        ->toContain('chmod 0755 /usr/local/bin/secpal-http-live')
        ->toContain('find /app -xdev -type d -exec chmod 0755 {} +')
        ->toContain('find /app -xdev -type f -exec chmod 0644 {} +')
        ->toContain('USER secpal')
        ->toContain('EXPOSE 8080')
        ->toContain('HEALTHCHECK NONE')
        ->not->toContain('artisan migrate')
        ->not->toContain('octane');

    expect($caddyfile)
        ->toContain('admin off', 'auto_https off', ':8080')
        ->toContain('root * /app/public')
        ->toContain('php_server')
        ->toContain(
            'request>uri query',
            'replace token REDACTED',
            'replace email REDACTED',
            'replace expires REDACTED',
            'replace signature REDACTED',
        )
        ->toContain('/.htaccess', '/*.license')
        ->not->toContain('worker');

    expect($productionIni)->toContain('upload_max_filesize=10M', 'post_max_size=12M');
    expect($workflow)->toContain('"storage/**"', '"artisan"', '"LICENSE"', '"THIRD-PARTY-NOTICES.md"', '"LICENSES/**"');
    expect($documentation)->toContain('/app/storage/app/private')->toContain('persistent')->and($dockerignore)->toMatch('/^storage\/app$/m');
    expect($dockerignore)->toMatch('/^\*\*\/\*\.sqlite\*$/m');
    expect($smokeScript)
        ->toContain('php --ini | grep -Fq "/usr/local/etc/php/conf.d/zz-secpal-production.ini"')
        ->toContain('test -r "$ini"')
        ->toContain('test ! -w "$ini"')
        ->toContain('test "$(stat -c "%U:%G %a" "$ini")" = "root:root 644"')
        ->toContain('test -r "$caddyfile"')
        ->toContain('test ! -w "$caddyfile"')
        ->toContain('test "$(stat -c "%U:%G %a" "$caddyfile")" = "root:root 644"')
        ->toContain('test -r "$healthcheck"')
        ->toContain('test -x "$healthcheck"')
        ->toContain('test ! -w "$healthcheck"')
        ->toContain('test "$(stat -c "%U:%G %a" "$healthcheck")" = "root:root 755"')
        ->toContain('if test -e "$sqlite_probe"; then')
        ->toContain('chmod 0444 "$port_probe"')
        ->toContain('-v "${port_probe}:/tmp/assert-port-closed.php:ro"');
    expect($proxyConfig)->toContain('TRUSTED_PROXIES');
});

it('distinguishes a listening TCP port from a closed port', function (): void {
    $helper = dirname(__DIR__, 2).'/tests/docker/assert-port-closed.php';
    expect(is_file($helper))->toBeTrue('Missing closed-port assertion helper.');

    $errorCode = 0;
    $errorMessage = '';
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($server)->not->toBeFalse("Unable to open local TCP listener: {$errorMessage} ({$errorCode})");
    assert(is_resource($server));

    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $port = (string) substr($address, (int) strrpos($address, ':') + 1);

    try {
        $listeningProbe = new Symfony\Component\Process\Process([PHP_BINARY, $helper, '127.0.0.1', $port]);
        $listeningProbe->run();
        expect($listeningProbe->getExitCode())->toBe(1, $listeningProbe->getErrorOutput());
    } finally {
        fclose($server);
    }

    $closedProbe = new Symfony\Component\Process\Process([PHP_BINARY, $helper, '127.0.0.1', $port]);
    $closedProbe->run();
    expect($closedProbe->getExitCode())->toBe(0, $closedProbe->getErrorOutput());

    $unresolvedProbe = new Symfony\Component\Process\Process([PHP_BINARY, $helper, 'unresolvable.invalid', $port]);
    $unresolvedProbe->run();
    expect($unresolvedProbe->getExitCode())->toBe(2, $unresolvedProbe->getErrorOutput());
});
