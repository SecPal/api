<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Tests\Support\TestCaseBootstrapEnvironmentProbe;

/** @return array<string, string|false> */
function captureParallelTestEnvironment(): array
{
    $keys = [
        'LARAVEL_PARALLEL_TESTING', 'TEST_TOKEN', 'SECPAL_TEST_DATABASE_BASE',
        'SECPAL_TEST_DATABASE', 'SECPAL_TEST_SCHEMA',
    ];

    return array_combine($keys, array_map(getenv(...), $keys));
}

/** @param array<string, string|false> $environment */
function restoreParallelTestEnvironment(array $environment): void
{
    foreach ($environment as $key => $value) {
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

test('phpunit environment overrides are applied before bootstrap-sensitive test setup', function (): void {
    $originalAppEnv = getenv('APP_ENV');
    $originalDbConnection = getenv('DB_CONNECTION');
    $originalDbDatabase = getenv('DB_DATABASE');
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $originalTestDatabaseBase = getenv('SECPAL_TEST_DATABASE_BASE');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('APP_ENV=staging');
        putenv('DB_CONNECTION=mysql');
        putenv('DB_DATABASE=secpal');
        putenv('LARAVEL_PARALLEL_TESTING');
        putenv('TEST_TOKEN');
        putenv('SECPAL_TEST_DATABASE_BASE=stale_test_base');
        putenv('SECPAL_TEST_DATABASE');
        putenv('SECPAL_TEST_SCHEMA');

        $_ENV['APP_ENV'] = 'staging';
        $_ENV['DB_CONNECTION'] = 'mysql';
        $_ENV['DB_DATABASE'] = 'secpal';
        unset(
            $_ENV['LARAVEL_PARALLEL_TESTING'],
            $_ENV['TEST_TOKEN'],
            $_ENV['SECPAL_TEST_DATABASE'],
            $_ENV['SECPAL_TEST_SCHEMA'],
        );
        $_ENV['SECPAL_TEST_DATABASE_BASE'] = 'stale_test_base';

        $_SERVER['APP_ENV'] = 'staging';
        $_SERVER['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_DATABASE'] = 'secpal';
        unset(
            $_SERVER['LARAVEL_PARALLEL_TESTING'],
            $_SERVER['TEST_TOKEN'],
            $_SERVER['SECPAL_TEST_DATABASE'],
            $_SERVER['SECPAL_TEST_SCHEMA'],
        );
        $_SERVER['SECPAL_TEST_DATABASE_BASE'] = 'stale_test_base';

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('APP_ENV'))->toBe('testing')
            ->and(getenv('DB_CONNECTION'))->toBe('pgsql')
            ->and(getenv('DB_DATABASE'))->toBe('testing')
            ->and(getenv('SECPAL_TEST_DATABASE_BASE'))->toBe('testing')
            ->and(getenv('SECPAL_TEST_DATABASE'))->toBe('testing')
            ->and(getenv('SECPAL_TEST_SCHEMA'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName()
            );
    } finally {
        foreach ([
            'APP_ENV' => $originalAppEnv,
            'DB_CONNECTION' => $originalDbConnection,
            'DB_DATABASE' => $originalDbDatabase,
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE_BASE' => $originalTestDatabaseBase,
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

test('Laravel parallel bootstrap only normalizes a known inherited worker database before its token is available', function (
    string $configuredTestDatabase,
    string $expectedTestDatabase,
    ?string $knownTestDatabaseBase,
): void {
    $originalEnvironment = captureParallelTestEnvironment();

    try {
        putenv('LARAVEL_PARALLEL_TESTING=1');
        putenv('TEST_TOKEN');
        putenv('SECPAL_TEST_DATABASE='.$configuredTestDatabase);
        putenv('SECPAL_TEST_SCHEMA');

        if ($knownTestDatabaseBase === null) {
            putenv('SECPAL_TEST_DATABASE_BASE');
            unset($_ENV['SECPAL_TEST_DATABASE_BASE'], $_SERVER['SECPAL_TEST_DATABASE_BASE']);
        } else {
            putenv('SECPAL_TEST_DATABASE_BASE='.$knownTestDatabaseBase);
            $_ENV['SECPAL_TEST_DATABASE_BASE'] = $knownTestDatabaseBase;
            $_SERVER['SECPAL_TEST_DATABASE_BASE'] = $knownTestDatabaseBase;
        }

        $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
        $_ENV['SECPAL_TEST_DATABASE'] = $configuredTestDatabase;
        $_SERVER['SECPAL_TEST_DATABASE'] = $configuredTestDatabase;
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('SECPAL_TEST_DATABASE_BASE'))->toBe($knownTestDatabaseBase ?? $configuredTestDatabase)
            ->and(getenv('SECPAL_TEST_DATABASE'))->toBe($expectedTestDatabase);
    } finally {
        restoreParallelTestEnvironment($originalEnvironment);
    }
})->with([
    'default inherited worker database' => ['testing_test_5', 'testing', 'testing'],
    'caller-provided suffix-shaped base' => ['ci_test_20260715', 'ci_test_20260715', null],
    'default-prefixed caller base with suffix-shaped ending' => ['testing_test_20260715', 'testing_test_20260715', null],
]);

test('parallel test token aligns Laravel and effective isolated database naming', function (
    string $baseTestDatabase,
    string $workerTestDatabase,
): void {
    $originalEnvironment = captureParallelTestEnvironment();

    try {
        putenv('LARAVEL_PARALLEL_TESTING=1');
        putenv('TEST_TOKEN=7');
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE='.$baseTestDatabase);
        putenv('SECPAL_TEST_SCHEMA');
        unset($_ENV['SECPAL_TEST_DATABASE_BASE'], $_SERVER['SECPAL_TEST_DATABASE_BASE']);
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);
        $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';
        $_ENV['SECPAL_TEST_DATABASE'] = $baseTestDatabase;
        $_SERVER['SECPAL_TEST_DATABASE'] = $baseTestDatabase;

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        $configuredDatabase = (string) getenv('SECPAL_TEST_DATABASE');
        TestCaseBootstrapEnvironmentProbe::resetParallelTestDatabaseName();
        $probe = new TestCaseBootstrapEnvironmentProbe('createApplication');

        expect(getenv('SECPAL_TEST_DATABASE_BASE'))->toBe($baseTestDatabase)
            ->and($configuredDatabase)->toBe($baseTestDatabase)
            ->and($probe->parallelTestDatabaseName($configuredDatabase))->toBe($workerTestDatabase)
            ->and(TestCaseBootstrapEnvironmentProbe::effectiveIsolatedTestDatabaseName())->toBe($workerTestDatabase)
            ->and(getenv('SECPAL_TEST_SCHEMA'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName()
            );
    } finally {
        TestCaseBootstrapEnvironmentProbe::resetParallelTestDatabaseName();

        restoreParallelTestEnvironment($originalEnvironment);
    }
})->with([
    'default database base' => ['testing', 'testing_test_7'],
    'caller-provided database base' => ['ci_precreated_testing', 'ci_precreated_testing_test_7'],
    'caller-provided suffix-shaped base' => ['ci_test_20260715', 'ci_test_20260715_test_7'],
    'default-prefixed caller base with suffix-shaped ending' => ['testing_test_20260715', 'testing_test_20260715_test_7'],
]);

test('parallel workers normalize inherited tokenized databases before Laravel applies its token', function (string $inheritedDatabase): void {
    $originalEnvironment = captureParallelTestEnvironment();

    try {
        putenv('LARAVEL_PARALLEL_TESTING=1');
        putenv('TEST_TOKEN=7');
        putenv('SECPAL_TEST_DATABASE_BASE=testing');
        putenv('SECPAL_TEST_DATABASE='.$inheritedDatabase);
        putenv('SECPAL_TEST_SCHEMA');
        $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';
        $_ENV['SECPAL_TEST_DATABASE_BASE'] = 'testing';
        $_SERVER['SECPAL_TEST_DATABASE_BASE'] = 'testing';
        $_ENV['SECPAL_TEST_DATABASE'] = $inheritedDatabase;
        $_SERVER['SECPAL_TEST_DATABASE'] = $inheritedDatabase;
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        $configuredDatabase = (string) getenv('SECPAL_TEST_DATABASE');
        $probe = new TestCaseBootstrapEnvironmentProbe('createApplication');

        expect(getenv('SECPAL_TEST_DATABASE_BASE'))->toBe('testing')
            ->and($configuredDatabase)->toBe('testing')
            ->and($probe->parallelTestDatabaseName($configuredDatabase))->toBe('testing_test_7')
            ->and(TestCaseBootstrapEnvironmentProbe::effectiveIsolatedTestDatabaseName())->toBe('testing_test_7');
    } finally {
        restoreParallelTestEnvironment($originalEnvironment);
    }
})->with([
    'different worker token' => 'testing_test_5',
    'repeated worker tokens' => 'testing_test_5_test_6',
]);

test('test token isolates the effective database without the Laravel parallel flag', function (): void {
    $originalEnvironment = captureParallelTestEnvironment();

    try {
        putenv('LARAVEL_PARALLEL_TESTING');
        putenv('TEST_TOKEN=7');
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE=testing');
        putenv('SECPAL_TEST_SCHEMA');
        unset($_ENV['LARAVEL_PARALLEL_TESTING'], $_SERVER['LARAVEL_PARALLEL_TESTING']);
        unset($_ENV['SECPAL_TEST_DATABASE_BASE'], $_SERVER['SECPAL_TEST_DATABASE_BASE']);
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';
        $_ENV['SECPAL_TEST_DATABASE'] = 'testing';
        $_SERVER['SECPAL_TEST_DATABASE'] = 'testing';
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $application = TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        expect(getenv('SECPAL_TEST_DATABASE'))->toBe('testing_test_7')
            ->and(TestCaseBootstrapEnvironmentProbe::isolatedTestDatabaseName('testing'))->toBe('testing_test_7')
            ->and($application['config']->get('database.connections.pgsql.database'))->toBe('testing_test_7')
            ->and(TestCaseBootstrapEnvironmentProbe::effectiveIsolatedTestDatabaseName())->toBe('testing_test_7');
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        restoreParallelTestEnvironment($originalEnvironment);
    }
});

test('non-numeric parallel test tokens produce path-safe isolated database names', function (): void {
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $originalTestDatabaseBase = getenv('SECPAL_TEST_DATABASE_BASE');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');
    $testToken = 'worker/../seven';
    $normalizedTestToken = substr(hash('sha256', $testToken), 0, 32);

    try {
        putenv('LARAVEL_PARALLEL_TESTING=1');
        putenv('TEST_TOKEN='.$testToken);
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE');
        putenv('SECPAL_TEST_SCHEMA');
        $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        $_ENV['TEST_TOKEN'] = $testToken;
        $_SERVER['TEST_TOKEN'] = $testToken;
        unset(
            $_ENV['SECPAL_TEST_DATABASE_BASE'],
            $_SERVER['SECPAL_TEST_DATABASE_BASE'],
            $_ENV['SECPAL_TEST_DATABASE'],
            $_SERVER['SECPAL_TEST_DATABASE'],
        );
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        $configuredDatabase = (string) getenv('SECPAL_TEST_DATABASE');
        $probe = new TestCaseBootstrapEnvironmentProbe('createApplication');

        expect(getenv('TEST_TOKEN'))->toBe($normalizedTestToken)
            ->and($_ENV['TEST_TOKEN'])->toBe($normalizedTestToken)
            ->and($_SERVER['TEST_TOKEN'])->toBe($normalizedTestToken)
            ->and($configuredDatabase)->toBe('testing')
            ->and(TestCaseBootstrapEnvironmentProbe::isolatedTestDatabaseName($configuredDatabase))
            ->toBe('testing_test_'.$normalizedTestToken)
            ->and($probe->parallelTestDatabaseName($configuredDatabase))
            ->toBe('testing_test_'.$normalizedTestToken)
            ->and(TestCaseBootstrapEnvironmentProbe::effectiveIsolatedTestDatabaseName())
            ->toBe('testing_test_'.$normalizedTestToken);
    } finally {
        foreach ([
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE_BASE' => $originalTestDatabaseBase,
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
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $originalTestDatabaseBase = getenv('SECPAL_TEST_DATABASE_BASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');

    try {
        putenv('LARAVEL_PARALLEL_TESTING');
        putenv('TEST_TOKEN');
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE');
        putenv('SECPAL_TEST_SCHEMA=ci_precreated_schema');
        unset($_ENV['LARAVEL_PARALLEL_TESTING'], $_SERVER['LARAVEL_PARALLEL_TESTING']);
        unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
        unset(
            $_ENV['SECPAL_TEST_DATABASE_BASE'],
            $_SERVER['SECPAL_TEST_DATABASE_BASE'],
            $_ENV['SECPAL_TEST_DATABASE'],
            $_SERVER['SECPAL_TEST_DATABASE'],
        );
        $_ENV['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';
        $_SERVER['SECPAL_TEST_SCHEMA'] = 'ci_precreated_schema';

        TestCaseBootstrapEnvironmentProbe::applyPhpUnitEnvironmentOverrides();

        expect(getenv('SECPAL_TEST_DATABASE'))->toBe('testing')
            ->and(getenv('SECPAL_TEST_SCHEMA'))->toBe('ci_precreated_schema');
    } finally {
        foreach ([
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE_BASE' => $originalTestDatabaseBase,
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
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $originalTestDatabaseBase = getenv('SECPAL_TEST_DATABASE_BASE');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('LARAVEL_PARALLEL_TESTING');
        putenv('TEST_TOKEN');
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE=ci_precreated_testing');
        putenv('SECPAL_TEST_SCHEMA=ci_precreated_schema');
        unset($_ENV['LARAVEL_PARALLEL_TESTING'], $_SERVER['LARAVEL_PARALLEL_TESTING']);
        unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
        unset($_ENV['SECPAL_TEST_DATABASE_BASE'], $_SERVER['SECPAL_TEST_DATABASE_BASE']);
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
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE_BASE' => $originalTestDatabaseBase,
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
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $originalTestDatabaseBase = getenv('SECPAL_TEST_DATABASE_BASE');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('LARAVEL_PARALLEL_TESTING');
        putenv('TEST_TOKEN');
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE=ci_precreated_testing');
        putenv('SECPAL_TEST_SCHEMA');
        unset($_ENV['LARAVEL_PARALLEL_TESTING'], $_SERVER['LARAVEL_PARALLEL_TESTING']);
        unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
        unset($_ENV['SECPAL_TEST_DATABASE_BASE'], $_SERVER['SECPAL_TEST_DATABASE_BASE']);
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
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE_BASE' => $originalTestDatabaseBase,
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
    $originalParallelTesting = getenv('LARAVEL_PARALLEL_TESTING');
    $originalTestToken = getenv('TEST_TOKEN');
    $originalTestDatabaseBase = getenv('SECPAL_TEST_DATABASE_BASE');
    $originalForcedTestDatabase = getenv('SECPAL_TEST_DATABASE');
    $originalForcedTestSchema = getenv('SECPAL_TEST_SCHEMA');

    try {
        putenv('LARAVEL_PARALLEL_TESTING=1');
        putenv('TEST_TOKEN=7');
        putenv('SECPAL_TEST_DATABASE_BASE');
        putenv('SECPAL_TEST_DATABASE=testing');
        putenv('SECPAL_TEST_SCHEMA');
        $_ENV['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        $_ENV['TEST_TOKEN'] = '7';
        $_SERVER['TEST_TOKEN'] = '7';
        unset($_ENV['SECPAL_TEST_DATABASE_BASE'], $_SERVER['SECPAL_TEST_DATABASE_BASE']);
        $_ENV['SECPAL_TEST_DATABASE'] = 'testing';
        $_SERVER['SECPAL_TEST_DATABASE'] = 'testing';
        unset($_ENV['SECPAL_TEST_SCHEMA'], $_SERVER['SECPAL_TEST_SCHEMA']);

        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();
        TestCaseBootstrapEnvironmentProbe::createBootstrapEnvironmentStub();

        $application = TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        $configuredDatabase = (string) $application['config']->get('database.connections.pgsql.database');
        $probe = new TestCaseBootstrapEnvironmentProbe('createApplication');

        expect($application['config']->get('database.default'))->toBe('pgsql')
            ->and($configuredDatabase)->toBe('testing')
            ->and($probe->parallelTestDatabaseName($configuredDatabase))->toBe('testing_test_7')
            ->and($application['config']->get('database.connections.pgsql.search_path'))->toBe(
                TestCaseBootstrapEnvironmentProbe::isolatedTestSchemaName().',public'
            );
    } finally {
        TestCaseBootstrapEnvironmentProbe::removeBootstrapEnvironmentStub();
        TestCaseBootstrapEnvironmentProbe::resetBootstrapEnvironmentState();

        foreach ([
            'LARAVEL_PARALLEL_TESTING' => $originalParallelTesting,
            'TEST_TOKEN' => $originalTestToken,
            'SECPAL_TEST_DATABASE_BASE' => $originalTestDatabaseBase,
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
