<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
        $configuredDatabase = (string) config('database.connections.pgsql.database');

        expect($configuredDatabase)->toMatch(PARALLEL_TEST_DATABASE_SUFFIX_PATTERN)
            ->and(DB::connection()->getDatabaseName())->toBe($configuredDatabase);
    }

    expect(fn () => DB::select('SELECT 1'))
        ->not->toThrow(QueryException::class);
});
