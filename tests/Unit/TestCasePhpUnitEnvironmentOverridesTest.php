<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Tests\Support\TestCaseBootstrapEnvironmentProbe;

test('phpunit environment overrides are applied before bootstrap-sensitive test setup', function (): void {
    $originalAppEnv = getenv('APP_ENV');
    $originalDbConnection = getenv('DB_CONNECTION');
    $originalDbDatabase = getenv('DB_DATABASE');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');

    try {
        putenv('APP_ENV=staging');
        putenv('DB_CONNECTION=mysql');
        putenv('DB_DATABASE=secpal');

        $_ENV['APP_ENV'] = 'staging';
        $_ENV['DB_CONNECTION'] = 'mysql';
        $_ENV['DB_DATABASE'] = 'secpal';

        $_SERVER['APP_ENV'] = 'staging';
        $_SERVER['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_DATABASE'] = 'secpal';

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('APP_ENV'))->toBe('testing')
            ->and(getenv('DB_CONNECTION'))->toBe('pgsql')
            ->and(getenv('DB_DATABASE'))->toBe('testing')
            ->and(getenv('SECPAL_TEST_DATABASE'))->toBe('testing');
    } finally {
        foreach ([
            'APP_ENV' => $originalAppEnv,
            'DB_CONNECTION' => $originalDbConnection,
            'DB_DATABASE' => $originalDbDatabase,
            'SECPAL_TEST_DATABASE' => $originalForcedTestDatabase,
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
    }
});
