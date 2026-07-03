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
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

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
            ->and(getenv('SECPAL_TEST_DATABASE'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestDatabaseName('testing')
            )
            ->and(getenv('SECPAL_TEST_SCHEMA'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName()
            );
    } finally {
        foreach ([
            'APP_ENV' => $originalAppEnv,
            'DB_CONNECTION' => $originalDbConnection,
            'DB_DATABASE' => $originalDbDatabase,
            'SECPAL_TEST_DATABASE' => $originalForcedTestDatabase,
            'SECPAL_TEST_SCHEMA' => $originalForcedTestSchema,
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

test('parallel test token wins over process-based isolated database naming', function (): void {
    $originalTestToken = getenv('TEST_TOKEN');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('TEST_TOKEN=7');
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('SECPAL_TEST_DATABASE'))->toBe('testing_test_7')
            ->and(getenv('SECPAL_TEST_SCHEMA'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName()
            );
    } finally {
        foreach ([
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE' => $originalForcedTestDatabase,
            'SECPAL_TEST_SCHEMA' => $originalForcedTestSchema,
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

test('bootstrap application normalization keeps the isolated database and schema configuration', function (): void {
    $originalTestToken = getenv('TEST_TOKEN');

    try {
        putenv('TEST_TOKEN=7');
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';

        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $application = TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        expect($application['config']->get('database.default'))->toBe('pgsql')
            ->and($application['config']->get('database.connections.pgsql.database'))->toBe('testing_test_7')
            ->and($application['config']->get('database.connections.pgsql.search_path'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName().',public'
            );
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        if ($originalTestToken === false) {
            putenv('TEST_TOKEN');
            unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
        } else {
            putenv('TEST_TOKEN='.$originalTestToken);
            $_ENV['TEST_TOKEN'] = $originalTestToken;
            $_SERVER['TEST_TOKEN'] = $originalTestToken;
        }
    }
});
