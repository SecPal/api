<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

function isLockedOpenTimestampsGraph(string $requirements): bool
{
    $requirements = str_replace("\r\n", "\n", $requirements);
    if (preg_match('/[^\x09\x0a\x20-\x7e]|^[ \t]*\#.*(?:coding[:=]|\\\\[ \t]*$)/m', $requirements) !== 0) {
        return false;
    }

    $packages = [];
    $declarations = str_replace("\\\n", '', $requirements);
    foreach (explode("\n", $declarations) as $declaration) {
        $declaration = trim($declaration);
        if ($declaration === '' || str_starts_with($declaration, '#')) {
            continue;
        }
        if (preg_match('/\A([A-Za-z0-9](?:[A-Za-z0-9_.-]*[A-Za-z0-9])?)==\d+\.\d+\.\d+(?:\h+--hash=sha256:[a-f0-9]{64})+\z/', $declaration, $match) !== 1) {
            return false;
        }
        $packages[] = preg_replace('/[-_.]+/', '-', strtolower($match[1]));
    }

    sort($packages);

    return $packages === ['opentimestamps', 'pycryptodomex', 'python-bitcoinlib'];
}

it('rejects mutable, unhashed or unapproved Python runtime requirements', function (): void {
    $manifest = (string) file_get_contents(dirname(__DIR__, 2).'/docker/python/opentimestamps-requirements.txt');

    expect(isLockedOpenTimestampsGraph($manifest))->toBeTrue()
        ->and(isLockedOpenTimestampsGraph(str_replace('python-bitcoinlib==', 'Python_Bitcoinlib==', $manifest)))->toBeTrue()
        ->and(isLockedOpenTimestampsGraph(str_replace('opentimestamps==', 'opentimestamps>=', $manifest)))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph((string) preg_replace('/\h*--hash=sha256:[a-f0-9]{64}/', '', $manifest)))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph(str_replace('sha256:', 'sha512:', $manifest)))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph($manifest."\n".$manifest))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph(str_replace('opentimestamps==', 'opentimestamps-client==', $manifest)))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph($manifest."\n# comment\\\nnot-a-requirement\n"))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph("# coding: latin1\n".$manifest))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph($manifest."\n# comment\rnot-a-requirement\n"))->toBeFalse()
        ->and(isLockedOpenTimestampsGraph($manifest."\n--extra-index-url https://packages.invalid/simple\n"))->toBeFalse();
});

it('keeps the runtime build context and production PHP configuration isolated', function (): void {
    $root = dirname(__DIR__, 2);
    $ignore = (string) file_get_contents($root.'/.containerignore');
    $php = (string) file_get_contents($root.'/docker/php-fpm/Containerfile');
    $productionIni = (string) file_get_contents($root.'/docker/php/conf.d/production.ini');
    $documentation = (string) file_get_contents($root.'/docs/containers.md');

    expect($ignore)->toMatch('/^storage\/app$/m')
        ->toMatch('/^\*\*\/\*\.sqlite\*$/m')
        ->toContain("docs/*\n!docs/ADDRESS_DATA.md");
    expect($php)->toContain('COPY docs/ADDRESS_DATA.md ./docs/ADDRESS_DATA.md', '--no-dev', '--no-scripts')
        ->not->toContain('redis-6.3.0', 'apt-get', '--allow-untrusted', 'edge/');
    expect($productionIni)->toContain('upload_max_filesize=10M', 'post_max_size=12M');
    expect($documentation)->toContain('/app/storage/app/private', 'persistent');
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

it('keeps FPM and the public HTTP image as the sole runtime definitions', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['docker/php-fpm/Containerfile', 'docker/php-fpm/pool.conf', 'docker/nginx/Containerfile', 'docker/nginx/nginx.conf', 'docker/nginx/fastcgi.conf'] as $path) {
        expect(is_file($root.'/'.$path))->toBeTrue("Missing paired runtime artifact: {$path}");
    }

    $php = (string) file_get_contents($root.'/docker/php-fpm/Containerfile');
    $pool = (string) file_get_contents($root.'/docker/php-fpm/pool.conf');
    $http = (string) file_get_contents($root.'/docker/nginx/Containerfile');
    $nginx = (string) file_get_contents($root.'/docker/nginx/nginx.conf');
    $fastcgi = (string) file_get_contents($root.'/docker/nginx/fastcgi.conf');

    expect($php)->toContain('php:8.4.', '-fpm-alpine3.24@sha256:', 'icu-data-full=', 'USER secpal', 'STOPSIGNAL SIGQUIT')
        ->toContain(
            'COPY docker/python/opentimestamps-requirements.txt /tmp/opentimestamps-requirements.txt',
            'pip3 install --break-system-packages --no-cache-dir --only-binary=:all:',
            '--require-hashes -r /tmp/opentimestamps-requirements.txt',
            'python3 -m pip check',
        )
        ->not->toContain('frankenphp', 'caddy', 'artisan migrate');
    expect($pool)->toContain('listen=/run/secpal-fpm/api.sock', 'listen.mode=0660', 'clear_env=yes', 'security.limit_extensions=.php', 'pm.max_children=4');
    expect($http)->toContain('nginx:', '-alpine3.24-slim@sha256:', 'COPY public /app/public', 'USER secpal', 'EXPOSE 8080')
        ->not->toContain('COPY app ', 'COPY vendor ', 'COPY config ', 'COPY storage ', 'APP_KEY', 'DB_PASSWORD');
    expect($nginx)->toContain('listen 8080;', 'root /app/public;', 'disable_symlinks on from=/app/public;', 'error_log /dev/null;', '"path":"$uri"')
        ->not->toContain('$request_uri', '$http_authorization', '$args');
    expect($fastcgi)->toContain('fastcgi_pass unix:/run/secpal-fpm/api.sock;', 'SCRIPT_FILENAME /app/public/index.php;', 'PATH_INFO "";', 'HTTP_PROXY "";', 'fastcgi_buffering off;');
    expect(file_exists($root.'/Containerfile'))->toBeFalse()
        ->and(file_exists($root.'/docker/frankenphp/Caddyfile'))->toBeFalse();
});

it('keeps secrets out of the FPM environment allowlist and reuses the qualified secret bootstrap', function (): void {
    $root = dirname(__DIR__, 2);
    $pool = (string) file_get_contents($root.'/docker/php-fpm/pool.conf');
    $smoke = (string) file_get_contents($root.'/tests/docker/smoke.sh');
    expect($pool)->toContain('clear_env=yes')->not->toContain('env[APP_KEY]', 'env[DB_PASSWORD]');
    expect($smoke)->toContain('production-secret-bootstrap.php', 'bf2282e3e112ac003360ecb3dff390eafe282dbd', 'NOCREATEDB', 'NOSUPERUSER');
});

it('cleans only smoke resources carrying its unique ownership label including PostgreSQL data', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/tests/docker/smoke.sh');
    expect($script)->toContain('secpal.dev/smoke', 'podman volume create --label',
        'podman volume inspect', 'podman volume rm', 'podman network inspect',
        'podman inspect --format');
    expect($script)->not->toContain('podman rm -f "$client" "$api" "$fpm" "$postgres"');
});
