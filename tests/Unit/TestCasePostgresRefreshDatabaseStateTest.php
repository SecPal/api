<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestCaseBootstrapEnvironmentProbe;

uses(RefreshDatabase::class);

test('postgres bootstrap clears stale migrated state when the migrations table is missing', function (): void {
    RefreshDatabaseState::$migrated = true;

    $bootstrapConnection = postgresBootstrapConnection();
    $hiddenMigrationTable = sprintf('migrations_hidden_%s', bin2hex(random_bytes(4)));

    try {
        renameTable($bootstrapConnection, 'migrations', $hiddenMigrationTable);

        TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

        expect(RefreshDatabaseState::$migrated)->toBeFalse();
    } finally {
        renameTableIfExists($bootstrapConnection, $hiddenMigrationTable, 'migrations');
        RefreshDatabaseState::$migrated = false;
    }
});

test('postgres bootstrap keeps migrated state when the migrations table is still present', function (): void {
    RefreshDatabaseState::$migrated = true;

    TestCaseBootstrapEnvironmentProbe::createBootstrapApplication();

    try {
        expect(RefreshDatabaseState::$migrated)->toBeTrue();
    } finally {
        RefreshDatabaseState::$migrated = false;
    }
});

function postgresBootstrapConnection(): PDO
{
    /** @var array{host: string, port: int|string, database: string, username: string, password: ?string} $config */
    $config = DB::connection()->getConfig();

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        ),
        $config['username'],
        $config['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function renameTable(PDO $connection, string $from, string $to): void
{
    $connection->exec(sprintf(
        'ALTER TABLE %s RENAME TO %s',
        quoteIdentifier($from),
        quoteIdentifier($to),
    ));
}

function renameTableIfExists(PDO $connection, string $from, string $to): void
{
    $connection->exec(sprintf(
        'ALTER TABLE IF EXISTS %s RENAME TO %s',
        quoteIdentifier($from),
        quoteIdentifier($to),
    ));
}

function quoteIdentifier(string $identifier): string
{
    return '"'.str_replace('"', '""', $identifier).'"';
}
