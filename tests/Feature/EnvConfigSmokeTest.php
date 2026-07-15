<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

test('essential config values are set', function (): void {
    expect(config('app.env'))->not->toBeNull();
    expect(config('database.default'))->not->toBeNull();
});

test('application config is loaded correctly', function (): void {
    expect(config('app.name'))->not->toBeNull();
    expect(config('app.debug'))->not->toBeNull();
});

test('database connection is working', function (): void {
    $parallelTestToken = ParallelTesting::token();

    if ($parallelTestToken !== false) {
        $parallelTestToken = (string) $parallelTestToken;
        $expectedDatabase = 'testing_test_'.parallelTestDatabaseTokenSuffix($parallelTestToken);

        expect(config('database.connections.pgsql.database'))->toBe($expectedDatabase)
            ->and(DB::connection()->getDatabaseName())->toBe($expectedDatabase);
    }

    expect(fn () => DB::select('SELECT 1'))
        ->not->toThrow(QueryException::class);
});
