<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 SecPal
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('essential config values are set', function (): void {
    expect(config('app.env'))->not->toBeNull();
    expect(config('database.default'))->not->toBeNull();
});

test('application config is loaded correctly', function (): void {
    expect(config('app.name'))->not->toBeNull();
    expect(config('app.debug'))->not->toBeNull();
});

test('database connection is working', function (): void {
    // Long parallel+coverage runs can exhaust pooled connections; retry up to 3 times.
    $lastException = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            DB::reconnect();
            DB::select('SELECT 1');

            return;
        } catch (QueryException $e) {
            $lastException = $e;
        }
    }

    expect($lastException)->toBeNull(
        sprintf('DB connection failed after 3 retries: %s', $lastException?->getMessage() ?? ''),
    );
});
