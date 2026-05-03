<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Tests\Support\TestCaseBootstrapEnvironmentProbe;

test('accepts writable postgres parallel test database access details', function (): void {
    TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_3', [
        'current_user' => 'secpal_app',
        'database_owner' => 'secpal_app',
        'schema_owner' => 'pg_database_owner',
        'can_create' => true,
    ]);

    expect(true)->toBeTrue();
});

test('fails fast when an existing postgres parallel test database is not writable', function (): void {
    expect(function (): void {
        TestCaseBootstrapEnvironmentProbe::assertWritableParallelTestDatabase('testing_test_8', [
            'current_user' => 'secpal_app',
            'database_owner' => 'postgres',
            'schema_owner' => 'pg_database_owner',
            'can_create' => false,
        ]);
    })
        ->toThrow(
            RuntimeException::class,
            'PostgreSQL test database "testing_test_8" exists but the configured user "secpal_app" cannot create tables in schema "public". Current database owner: "postgres". Current schema owner: "pg_database_owner". Ensure the database is owned by the configured app user or grant CREATE on schema public before running the test suite.'
        );
});
