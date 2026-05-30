<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\TestCaseBootstrapEnvironmentProbe;

afterEach(function (): void {
    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
    TestCaseBootstrapEnvironmentProbe::clearProbeEnvironmentPath();
    TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
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
