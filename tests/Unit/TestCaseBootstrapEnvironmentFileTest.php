<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\TestCaseBootstrapEnvironmentFileNameOverrideProbe;
use Tests\Support\TestCaseBootstrapEnvironmentProbe;

afterEach(function (): void {
    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
    TestCaseBootstrapEnvironmentProbe::clearProbeEnvironmentPath();
    TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
    TestCaseBootstrapEnvironmentFileNameOverrideProbe::removeBootstrapEnvironmentStub();
    TestCaseBootstrapEnvironmentFileNameOverrideProbe::clearProbeEnvironmentFileName();
    TestCaseBootstrapEnvironmentFileNameOverrideProbe::resetBootstrapEnvironmentState();
});

test('creates a dedicated test env file when no local env files exist', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());

    mkdir($probeDirectory, 0700, true);
    TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);

    $environmentFile = $probeDirectory.'/.env.testing.bootstrap';

    expect(is_file($probeDirectory.'/.env'))->toBeFalse()
        ->and(is_file($environmentFile))->toBeFalse();

    TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

    $contents = file_get_contents($environmentFile);

    expect(is_file($environmentFile))->toBeTrue()
        ->and(is_file($probeDirectory.'/.env'))->toBeFalse()
        ->and($contents)->toContain('Temporary test bootstrap env file')
        ->and($contents)->toContain('APP_ENV="testing"')
        ->and($contents)->toContain('APP_KEY=')
        ->and(fileperms($environmentFile) & 0777)->toBe(0600);

    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

    expect(is_file($environmentFile))->toBeFalse();

    rmdir($probeDirectory);
});

test('publishes bootstrap env updates via atomic file replacement', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());

    mkdir($probeDirectory, 0700, true);
    TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);

    $environmentFile = $probeDirectory.'/.env.testing.bootstrap';

    TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

    clearstatcache(true, $environmentFile);

    $firstInode = fileinode($environmentFile);

    TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
    TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

    clearstatcache(true, $environmentFile);

    expect($firstInode)->toBeInt()
        ->and(fileinode($environmentFile))->toBeInt()
        ->and(fileinode($environmentFile))->not->toBe($firstInode)
        ->and(file_get_contents($environmentFile))->toContain('APP_ENV="testing"');

    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

    expect(is_file($environmentFile))->toBeFalse();

    if (is_file(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath())) {
        unlink(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath());
    }

    rmdir($probeDirectory);
});

test('parallel workers use and clean up token-specific bootstrap env files', function (): void {
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $environmentFile = dirname(__DIR__, 2).'/.env.testing.bootstrap.7';

    try {
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        putenv('LARAVEL_PARALLEL_TESTING=1');
        putenv('TEST_TOKEN=7');
        $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';

        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        expect(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentFileName())
            ->toBe('.env.testing.bootstrap.7')
            ->and(is_file($environmentFile))->toBeTrue();

        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

        expect(is_file($environmentFile))->toBeFalse();
    } finally {
        foreach ([
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
        ] as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

        if (is_file(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath())) {
            unlink(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath());
        }
    }
});

test('reapplies the bootstrap env before each application boot when process vars leak', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());
    $originalAppEnv = getenv('APP_ENV');
    $originalAppKey = getenv('APP_KEY');
    $leakedAppKey = 'base64:leaked-app-key-should-not-survive';

    mkdir($probeDirectory, 0700, true);

    try {
        TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        putenv('APP_ENV=local');
        putenv('APP_KEY='.$leakedAppKey);
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
        $_ENV['APP_KEY'] = $leakedAppKey;
        $_SERVER['APP_KEY'] = $leakedAppKey;

        $application = TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        expect(getenv('APP_ENV'))->toBe('testing')
            ->and(getenv('APP_KEY'))->toBe(TestCaseBootstrapEnvironmentProbe::expectedTestAppKey())
            ->and($application->environment())->toBe('testing')
            ->and($application['config']->get('app.key'))->toBe(TestCaseBootstrapEnvironmentProbe::expectedTestAppKey());
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        foreach (['APP_ENV' => $originalAppEnv, 'APP_KEY' => $originalAppKey] as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        if (is_file(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath())) {
            unlink(TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath());
        }

        rmdir($probeDirectory);
    }
});

test('serializes bootstrap env writers before publishing the shared file', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for bootstrap writer lock coverage.');
    }

    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());

    mkdir($probeDirectory, 0700, true);
    TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);

    $lockFile = TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentLockFilePath();
    $lockHandle = fopen($lockFile, 'c+');

    expect($lockHandle)->toBeResource()
        ->and(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue();

    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $workerScript = sprintf(
        <<<'PHP'
require %s;
\Tests\Support\TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath(%s);
\Tests\Support\TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
\Tests\Support\TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();
PHP,
        var_export($autoloadPath, true),
        var_export($probeDirectory, true),
    );

    $process = proc_open(
        [PHP_BINARY, '-r', $workerScript],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2),
    );

    expect($process)->toBeResource();

    try {
        usleep(200000);

        $status = proc_get_status($process);

        expect($status)->toBeArray()
            ->and($status['running'] ?? false)->toBeTrue();
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0)
        ->and($stdout)->toBe('')
        ->and($stderr)->toBe('');

    if (is_file($lockFile)) {
        unlink($lockFile);
    }

    rmdir($probeDirectory);
});

test('isolates the generated test env file and runtime env state from inherited deployment bootstrap flags', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());
    $originalAppKey = getenv('APP_KEY');
    $originalBootstrapPublicEnabled = getenv('BOOTSTRAP_PUBLIC_ENABLED');
    $originalBootstrapMinimumSupportedAppVersion = getenv('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION');
    $originalBootstrapMinimumSupportedAppBuild = getenv('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD');
    $originalDbHost = getenv('DB_HOST');
    $originalDbPassword = getenv('DB_PASSWORD');
    $inheritedAppKey = 'base64:inherited-local-app-key-should-not-survive';

    mkdir($probeDirectory, 0700, true);
    file_put_contents(
        $probeDirectory.'/.env',
        implode("\n", [
            'APP_KEY=base64:local-env-key-should-not-survive',
            'BOOTSTRAP_PUBLIC_ENABLED=true',
            'BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION=9.9.9',
            'BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD=99999',
            'DB_HOST=bootstrap-probe.internal',
            'DB_PASSWORD=probe-secret',
            '',
        ]),
    );

    try {
        putenv('APP_KEY='.$inheritedAppKey);
        putenv('BOOTSTRAP_PUBLIC_ENABLED=true');
        putenv('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION=9.9.9');
        putenv('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD=99999');
        putenv('DB_HOST');
        putenv('DB_PASSWORD');
        $_ENV['APP_KEY'] = $inheritedAppKey;
        $_SERVER['APP_KEY'] = $inheritedAppKey;
        $_ENV['BOOTSTRAP_PUBLIC_ENABLED'] = 'true';
        $_SERVER['BOOTSTRAP_PUBLIC_ENABLED'] = 'true';
        $_ENV['BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION'] = '9.9.9';
        $_SERVER['BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION'] = '9.9.9';
        $_ENV['BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD'] = '99999';
        $_SERVER['BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD'] = '99999';
        unset(
            $_ENV['DB_HOST'],
            $_SERVER['DB_HOST'],
            $_ENV['DB_PASSWORD'],
            $_SERVER['DB_PASSWORD'],
        );

        TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $environmentFile = $probeDirectory.'/.env.testing.bootstrap';
        $contents = file_get_contents($environmentFile);

        expect(is_file($environmentFile))->toBeTrue()
            ->and($contents)->toContain('APP_ENV="testing"')
            ->and($contents)->toContain('APP_KEY="'.TestCaseBootstrapEnvironmentProbe::expectedTestAppKey().'"')
            ->and($contents)->toContain('DB_HOST="bootstrap-probe.internal"')
            ->and($contents)->toContain('DB_PASSWORD="probe-secret"')
            ->and($contents)->not->toContain('BOOTSTRAP_PUBLIC_ENABLED')
            ->and($contents)->not->toContain('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION')
            ->and($contents)->not->toContain('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD')
            ->and($contents)->not->toContain($inheritedAppKey)
            ->and(getenv('APP_KEY'))->toBe(TestCaseBootstrapEnvironmentProbe::expectedTestAppKey())
            ->and(getenv('BOOTSTRAP_PUBLIC_ENABLED'))->toBeFalse()
            ->and(getenv('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION'))->toBeFalse()
            ->and(getenv('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD'))->toBeFalse();
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

        foreach ([
            'APP_KEY' => $originalAppKey,
            'BOOTSTRAP_PUBLIC_ENABLED' => $originalBootstrapPublicEnabled,
            'BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION' => $originalBootstrapMinimumSupportedAppVersion,
            'BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD' => $originalBootstrapMinimumSupportedAppBuild,
            'DB_HOST' => $originalDbHost,
            'DB_PASSWORD' => $originalDbPassword,
        ] as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        unlink($probeDirectory.'/.env');
        rmdir($probeDirectory);
    }
});

test('clears BOOTSTRAP_* vars present only in the process env (variables_order without E)', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());
    $originalBootstrapProcessOnly = getenv('BOOTSTRAP_PROCESS_ONLY_FLAG');

    mkdir($probeDirectory, 0700, true);

    try {
        putenv('BOOTSTRAP_PROCESS_ONLY_FLAG=process-only');
        unset($_ENV['BOOTSTRAP_PROCESS_ONLY_FLAG'], $_SERVER['BOOTSTRAP_PROCESS_ONLY_FLAG']);

        TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        expect(getenv('BOOTSTRAP_PROCESS_ONLY_FLAG'))->toBeFalse();
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        if ($originalBootstrapProcessOnly === false) {
            putenv('BOOTSTRAP_PROCESS_ONLY_FLAG');
        } else {
            putenv('BOOTSTRAP_PROCESS_ONLY_FLAG='.$originalBootstrapProcessOnly);
            $_ENV['BOOTSTRAP_PROCESS_ONLY_FLAG'] = $originalBootstrapProcessOnly;
            $_SERVER['BOOTSTRAP_PROCESS_ONLY_FLAG'] = $originalBootstrapProcessOnly;
        }

        rmdir($probeDirectory);
    }
});

test('respects overridden bootstrap env file names when composing and loading the bootstrap env file', function (): void {
    $environmentFileName = '.env.testing.bootstrap.'.Str::uuid();
    $environmentFilePath = dirname(__DIR__, 2).'/'.$environmentFileName;

    try {
        TestCaseBootstrapEnvironmentFileNameOverrideProbe::useProbeEnvironmentFileName($environmentFileName);
        TestCaseBootstrapEnvironmentFileNameOverrideProbe::resetBootstrapEnvironmentState();

        expect(TestCaseBootstrapEnvironmentFileNameOverrideProbe::bootstrapEnvironmentFilePath())
            ->toBe($environmentFilePath)
            ->and(is_file($environmentFilePath))->toBeFalse();

        TestCaseBootstrapEnvironmentFileNameOverrideProbe::createBootstrapEnvironmentStub();

        $app = TestCaseBootstrapEnvironmentFileNameOverrideProbe::createBootstrapApplication();

        expect(is_file($environmentFilePath))->toBeTrue()
            ->and($app->environmentFile())->toBe($environmentFileName);
    } finally {
        TestCaseBootstrapEnvironmentFileNameOverrideProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentFileNameOverrideProbe::clearProbeEnvironmentFileName();
        TestCaseBootstrapEnvironmentFileNameOverrideProbe::resetBootstrapEnvironmentState();

        if (is_file($environmentFilePath)) {
            unlink($environmentFilePath);
        }
    }
});
