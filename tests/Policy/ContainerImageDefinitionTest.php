<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

it('defines the production API image contract', function (): void {
    $root = dirname(__DIR__, 2);
    $artifacts = ['Containerfile', '.containerignore', 'docker/frankenphp/Caddyfile', 'docker/php/conf.d/production.ini',
        'docker/python/opentimestamps-requirements.txt', 'docker/healthchecks/http-live.sh', 'tests/docker/smoke.sh',
        'tests/docker/assert-port-closed.php', '.github/workflows/container-image.yml', 'config/trustedproxy.php'];
    foreach ($artifacts as $path) {
        expect(is_file($root.'/'.$path))->toBeTrue("Missing container artifact: {$path}");
    }

    $dockerfile = file_get_contents($root.'/Containerfile');
    $dockerignore = file_get_contents($root.'/.containerignore');
    $caddyfile = file_get_contents($root.'/docker/frankenphp/Caddyfile');
    $productionIni = file_get_contents($root.'/docker/php/conf.d/production.ini');
    $pythonRequirements = file_get_contents($root.'/docker/python/opentimestamps-requirements.txt');
    $workflow = file_get_contents($root.'/.github/workflows/container-image.yml');
    $documentation = file_get_contents($root.'/docs/containers.md');
    $proxyConfig = file_get_contents($root.'/config/trustedproxy.php');
    $smokeScript = file_get_contents($root.'/tests/docker/smoke.sh');
    $extensionsStage = explode('FROM extensions AS dependencies', $dockerfile, 2)[0];
    $dependenciesStage = explode('FROM extensions AS dependencies', $dockerfile, 2)[1];
    $dependenciesStage = explode('FROM extensions AS runtime', $dependenciesStage, 2)[0];

    expect($dockerfile)
        ->toMatch('/^ARG FRANKENPHP_IMAGE=docker\\.io\\/dunglas\\/frankenphp:[0-9]+\\.[0-9]+\\.[0-9]+-php8\\.4\\.[0-9]+-alpine@sha256:[a-f0-9]{64}$/m')
        ->toMatch('/^ARG COMPOSER_IMAGE=docker\\.io\\/library\\/composer:[0-9]+\\.[0-9]+\\.[0-9]+@sha256:[a-f0-9]{64}$/m')
        ->toContain('FROM ${COMPOSER_IMAGE} AS composer', 'FROM ${FRANKENPHP_IMAGE} AS extensions')
        ->toContain('composer install')
        ->toMatch('/\bpython3=3\.14\.\d+-r\d+ py3-pip=\d+\.\d+\.\d+-r\d+\b/')
        ->toContain('curl=8.23.0-r0', 'libcurl=8.23.0-r0', 'brotli-libs=1.2.1-r0')
        ->toContain('--no-dev')
        ->toContain('--no-scripts')
        ->toContain('--require-hashes', '--only-binary=:all:', 'opentimestamps-requirements.txt')
        ->toContain('rm -f bootstrap/cache/*.php', '/config/caddy /config/psysh')
        ->not->toContain('redis-6.3.0', 'apt-get', 'dpkg', '--allow-untrusted', 'edge/')
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

    preg_match('/\bpython3=(\d+\.\d+\.\d+)-r\d+\b/', $extensionsStage, $pythonInput);
    expect(version_compare($pythonInput[1] ?? '0', '3.14.8', '>='))->toBeTrue();

    expect($pythonRequirements)
        ->toContain('opentimestamps==0.4.5 --hash=sha256:', '6bbcb1dd0f646484939e142462d9e532482bc74475cecf9c4903d4e1cd21f003', '55ccbe27f049743a4caf4f4221b166560d3438d0b1e5ab929e07ae1702a4d6fd', '43c446e2ba8df8889e0e16f02211c25b4934898384c1ec1ec04d7889c0333587', 'f489c4765093fb60e2edafdf223397bc716491b2b69fe74367b70d6999257a5c')
        ->not->toContain('opentimestamps-client', 'gitpython', 'gitdb', 'smmap', 'appdirs', 'pysocks');

    expect($smokeScript)
        ->toContain('DB_SSLMODE=verify-full', 'apk info --installed curl=8.23.0-r0', '--read-only', '--cap-drop=all', '--security-opt=no-new-privileges')
        ->toContain('DB_SSLROOTCERT=/run/secrets/postgresql-ca.crt')
        ->toContain("POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256'")
        ->toContain('-c password_encryption=scram-sha-256')
        ->toContain('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')
        ->not->toContain('valkey_image=');

    expect($extensionsStage)->not->toContain('unzip');
    expect($dependenciesStage)
        ->toContain('apk add --no-cache unzip=6.0-r16');

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
    expect(Symfony\Component\Yaml\Yaml::parse($workflow)['on']['pull_request']['paths'])->toContain('storage/**', 'artisan', 'LICENSE', 'THIRD-PARTY-NOTICES.md', 'LICENSES/**');
    expect($documentation)->toContain('/app/storage/app/private')->toContain('persistent')->and($dockerignore)->toMatch('/^storage\/app$/m');
    expect($dockerignore)->toMatch('/^\*\*\/\*\.sqlite\*$/m');
    expect($dockerignore)->toContain("docs/*\n!docs/ADDRESS_DATA.md")
        ->and($dockerfile)->toContain('COPY docs/ADDRESS_DATA.md ./docs/ADDRESS_DATA.md');
    expect(Symfony\Component\Yaml\Yaml::parse($workflow)['on']['pull_request']['paths'])->toContain('docs/ADDRESS_DATA.md');

    expect($smokeScript)
        ->toContain('notice=/app/docs/ADDRESS_DATA.md', 'test -r /app/LICENSES/ODbL-1.0.txt')
        ->toContain('assert_output_contains "$php_ini_output" \'/usr/local/etc/php/conf.d/zz-secpal-production.ini\'')
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

it('checks container command output without early-terminating pipelines', function (): void {
    $smokeScript = (string) file_get_contents(dirname(__DIR__, 2).'/tests/docker/smoke.sh');

    expect($smokeScript)
        ->toContain('php_version=$(podman run --rm "$image" php -v)')
        ->toContain('frankenphp_version=$(podman run --rm "$image" frankenphp version)')
        ->toContain('php_modules=$(podman run --rm "$image" php -m)')
        ->toContain('exit(extension_loaded("redis") ? 1 : 0)')
        ->not->toContain('php --ri redis')
        ->toContain('php_ini_output=$(podman run --rm "$image" php --ini)')
        ->toContain('podman run --rm "$image" python3 -c \'import opentimestamps\'')
        ->toContain('git_import=$(podman run --rm "$image" python3 -c \'import git\' 2>&1)')
        ->toContain('assert_output_contains "$git_import" \'ModuleNotFoundError\'')
        ->not->toMatch('/podman run[^\n]*\\|[ \t]*grep\\b/');
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

it('excludes obsolete Laravel Sail from the dependency graph', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['require'] + $composer['require-dev'])->not->toHaveKey('laravel/sail')
        ->and(array_column(array_merge($lock['packages'], $lock['packages-dev']), 'name'))->not->toContain('laravel/sail');
});
