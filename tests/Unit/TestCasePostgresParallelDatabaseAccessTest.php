<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Tests\Support\TestCaseBootstrapEnvironmentProbe;

test('accepts writable postgres parallel test database access details', function (): void {
    TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_3', 'public', [
        'current_user' => 'secpal_app',
        'database_owner' => 'secpal_app',
        'public_schema_owner' => 'pg_database_owner',
        'target_schema_owner' => 'pg_database_owner',
        'can_create_public_schema' => true,
        'can_create_target_schema' => true,
        'can_use_target_schema' => true,
        'can_create_schema' => true,
        'target_schema_exists' => true,
    ]);

    expect(true)->toBeTrue();
});

test('fails fast when an existing postgres parallel test database is not writable', function (): void {
    expect(function (): void {
        TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_8', 'public', [
            'current_user' => 'secpal_app',
            'database_owner' => 'postgres',
            'public_schema_owner' => 'pg_database_owner',
            'target_schema_owner' => 'pg_database_owner',
            'can_create_public_schema' => false,
            'can_create_target_schema' => false,
            'can_use_target_schema' => true,
            'can_create_schema' => false,
            'target_schema_exists' => true,
        ]);
    })
        ->toThrow(
            RuntimeException::class,
            'PostgreSQL test database "testing_test_8" exists but the configured user "secpal_app" cannot create tables in schema "public". Current database owner: "postgres". Current schema owner: "pg_database_owner". Ensure the database is owned by the configured app user or grant CREATE on schema public before running the test suite.'
        );
});

test('accepts writable isolated postgres schema access details when the schema already exists', function (): void {
    TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_4', 'test_proc_42', [
        'current_user' => 'secpal_app',
        'database_owner' => 'postgres',
        'public_schema_owner' => 'pg_database_owner',
        'target_schema_owner' => 'secpal_app',
        'can_create_public_schema' => false,
        'can_create_target_schema' => true,
        'can_use_target_schema' => true,
        'can_create_schema' => false,
        'target_schema_exists' => true,
    ]);

    expect(true)->toBeTrue();
});

test('fails fast when an isolated postgres schema is missing and the user cannot create schemas', function (): void {
    expect(function (): void {
        TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_9', 'test_proc_77', [
            'current_user' => 'secpal_app',
            'database_owner' => 'postgres',
            'public_schema_owner' => 'pg_database_owner',
            'target_schema_owner' => null,
            'can_create_public_schema' => true,
            'can_create_target_schema' => false,
            'can_use_target_schema' => false,
            'can_create_schema' => false,
            'target_schema_exists' => false,
        ]);
    })
        ->toThrow(
            RuntimeException::class,
            'PostgreSQL test database "testing_test_9" exists but the configured user "secpal_app" cannot create the isolated test schema "test_proc_77". Current database owner: "postgres". Grant CREATE on the database or pre-create the schema with CREATE privilege for the app user before running the test suite.'
        );
});

test('fails fast when an existing isolated postgres schema lacks usage for the configured user', function (): void {
    expect(function (): void {
        TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_10', 'test_proc_99', [
            'current_user' => 'secpal_app',
            'database_owner' => 'postgres',
            'public_schema_owner' => 'pg_database_owner',
            'target_schema_owner' => 'schema_owner',
            'can_create_public_schema' => false,
            'can_create_target_schema' => true,
            'can_use_target_schema' => false,
            'can_create_schema' => false,
            'target_schema_exists' => true,
        ]);
    })
        ->toThrow(
            RuntimeException::class,
            'PostgreSQL test database "testing_test_10" exists but the configured user "secpal_app" cannot use the isolated test schema "test_proc_99". Current database owner: "postgres". Current schema owner: "schema_owner". Grant USAGE and CREATE on that schema or recreate it with the app user as owner before running the test suite.'
        );
});

test('does not try to create an isolated postgres schema when it already exists', function (): void {
    expect(TestCaseBootstrapEnvironmentProbe::shouldCreateIsolatedTestSchema([
        'current_user' => 'secpal_app',
        'database_owner' => 'postgres',
        'public_schema_owner' => 'pg_database_owner',
        'target_schema_owner' => 'schema_owner',
        'can_create_public_schema' => false,
        'can_create_target_schema' => true,
        'can_use_target_schema' => true,
        'can_create_schema' => false,
        'target_schema_exists' => true,
    ]))->toBeFalse();
});
