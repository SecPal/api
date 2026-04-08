<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\TestCaseBootstrapEnvironmentProbe;

afterEach(function (): void {
    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
    TestCaseBootstrapEnvironmentProbe::clearProbeEnvironmentPath();
});

test('creates a temporary .env stub when no local env files exist', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());

    mkdir($probeDirectory, 0700, true);
    TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);

    $environmentFile = $probeDirectory.'/.env';

    expect(is_file($environmentFile))->toBeFalse();

    TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

    expect(is_file($environmentFile))->toBeTrue()
        ->and(file_get_contents($environmentFile))->toContain('Temporary test bootstrap stub');

    TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();

    expect(is_file($environmentFile))->toBeFalse();

    rmdir($probeDirectory);
});

test('does not create a temporary bootstrap stub when a local .env already exists', function (): void {
    $probeDirectory = storage_path('framework/testing/bootstrap-env-'.Str::uuid());

    mkdir($probeDirectory, 0700, true);
    file_put_contents($probeDirectory.'/.env', "APP_ENV=testing\n");

    TestCaseBootstrapEnvironmentProbe::useProbeEnvironmentPath($probeDirectory);
    TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

    expect(file_get_contents($probeDirectory.'/.env'))->toBe("APP_ENV=testing\n");

    unlink($probeDirectory.'/.env');
    rmdir($probeDirectory);
});
