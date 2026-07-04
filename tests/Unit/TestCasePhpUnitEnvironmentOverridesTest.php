<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
        putenv('SECPAL_TEST_DATABASE');
        putenv('SECPAL_TEST_SCHEMA');

        $_ENV['APP_ENV'] = 'staging';
        $_ENV['DB_CONNECTION'] = 'mysql';
        $_ENV['DB_DATABASE'] = 'secpal';
        unset($_ENV['SECPAL_TEST_DATABASE'], $_ENV['SECPAL_TEST_SCHEMA']);

        $_SERVER['APP_ENV'] = 'staging';
        $_SERVER['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_DATABASE'] = 'secpal';
        unset($_SERVER['SECPAL_TEST_DATABASE'], $_SERVER['SECPAL_TEST_SCHEMA']);

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
        putenv('SECPAL_TEST_DATABASE');
        putenv('SECPAL_TEST_SCHEMA');
        unset($_ENV['SECPAL_TEST_DATABASE'], $_SERVER['SECPAL_TEST_DATABASE']);
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);
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

test('phpunit environment overrides preserve a caller-provided isolated schema override', function (): void {
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');

    try {
        putenv('SECPAL_TEST_DATABASE');
        putenv('SECPAL_TEST_SCHEMA=ci_precreated_schema');
        unset($_ENV['SECPAL_TEST_DATABASE'], $_SERVER['SECPAL_TEST_DATABASE']);
        $_ENV['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';
        $_SERVER['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('SECPAL_TEST_DATABASE'))->toBe(
            TestCaseBootstrapEnvironmentProbe::isolatedTestDatabaseName('testing')
        )->and(getenv('SECPAL_TEST_SCHEMA'))->toBe('ci_precreated_schema');
    } finally {
        foreach ([
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

test('effective isolated schema name preserves a caller-provided schema override for bootstrap probes', function (): void {
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('SECPAL_TEST_SCHEMA=ci_precreated_schema');
        $_ENV['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';
        $_SERVER['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';

        expect(TestCaseBootstrapEnvironmentProbe::effectiveIsolatedTestSchemaName())
            ->toBe('ci_precreated_schema');
    } finally {
        if ($originalForcedTestSchema === false) {
            putenv('SECPAL_TEST_SCHEMA');
            unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

            return;
        }

        putenv('SECPAL_TEST_SCHEMA='.$originalForcedTestSchema);
        $_ENV['SECPAL_TEST_SCHEMA'] = $originalForcedTestSchema;
        $_SERVER['SECPAL_TEST_SCHEMA'] = $originalForcedTestSchema;
    }
});

test('bootstrap application normalization preserves a caller-provided isolated database override', function (): void {
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('SECPAL_TEST_DATABASE=ci_precreated_testing');
        putenv('SECPAL_TEST_SCHEMA=ci_precreated_schema');
        $_ENV['SECPAL_TEST_DATABASE'] = 'ci_precreated_testing';
        $_ENV['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';
        $_SERVER['SECPAL_TEST_DATABASE'] = 'ci_precreated_testing';
        $_SERVER['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';

        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $application = TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        expect($application['config']->get('database.default'))->toBe('pgsql')
            ->and($application['config']->get('database.connections.pgsql.database'))->toBe('ci_precreated_testing')
            ->and($application['config']->get('database.connections.pgsql.search_path'))->toBe('ci_precreated_schema,public');
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        foreach ([
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

test('bootstrap schema validation rejects isolated schema names that require quoting', function (): void {
    expect(fn (): mixed => TestCaseBootstrapEnvironmentProbe::assertValidSchemaName('1_schema'))
        ->toThrow(RuntimeException::class, 'Invalid PostgreSQL test schema name: 1_schema');

    expect(fn (): mixed => TestCaseBootstrapEnvironmentProbe::assertValidSchemaName('CiSchema'))
        ->toThrow(RuntimeException::class, 'Invalid PostgreSQL test schema name: CiSchema');
});

test('phpunit environment overrides preserve a caller-provided isolated database override', function (): void {
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('SECPAL_TEST_DATABASE=ci_precreated_testing');
        putenv('SECPAL_TEST_SCHEMA');
        $_ENV['SECPAL_TEST_DATABASE'] = 'ci_precreated_testing';
        $_SERVER['SECPAL_TEST_DATABASE'] = 'ci_precreated_testing';
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('SECPAL_TEST_DATABASE'))->toBe('ci_precreated_testing')
            ->and(getenv('SECPAL_TEST_SCHEMA'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName()
            );
    } finally {
        foreach ([
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
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('TEST_TOKEN=7');
        putenv('SECPAL_TEST_SCHEMA');
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $application = TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        expect($application['config']->get('database.default'))->toBe('pgsql')
            ->and($application['config']->get('database.connections.pgsql.database'))->toBe('testing')
            ->and($application['config']->get('database.connections.pgsql.search_path'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName().',public'
            );
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

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
