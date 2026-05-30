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
        ->and($contents)->toContain('APP_KEY=');

    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

    expect(is_file($environmentFile))->toBeFalse();

    rmdir($probeDirectory);
});

test('isolates the generated test env file from deployment bootstrap flags in local .env', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());
    $originalDbHost = getenv('DB_HOST');
    $originalDbPassword = getenv('DB_PASSWORD');

    mkdir($probeDirectory, 0700, true);
    file_put_contents(
        $probeDirectory.'/.env',
        implode("\n", [
            'BOOTSTRAP_PUBLIC_ENABLED=true',
            'BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION=9.9.9',
            'BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD=99999',
            'DB_HOST=bootstrap-probe.internal',
            'DB_PASSWORD=probe-secret',
            '',
        ]),
    );

    try {
        putenv('DB_HOST');
        putenv('DB_PASSWORD');
        unset($_ENV['DB_HOST'], $_SERVER['DB_HOST'], $_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD']);

        TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $environmentFile = $probeDirectory.'/.env.testing.bootstrap';
        $contents = file_get_contents($environmentFile);

        expect(is_file($environmentFile))->toBeTrue()
            ->and($contents)->toContain('APP_ENV="testing"')
            ->and($contents)->toContain('DB_HOST="bootstrap-probe.internal"')
            ->and($contents)->toContain('DB_PASSWORD="probe-secret"')
            ->and($contents)->not->toContain('BOOTSTRAP_PUBLIC_ENABLED')
            ->and($contents)->not->toContain('BOOTSTRAP_MINIMUM_SUPPORTED_APP_VERSION')
            ->and($contents)->not->toContain('BOOTSTRAP_MINIMUM_SUPPORTED_APP_BUILD');
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

        foreach ([
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
