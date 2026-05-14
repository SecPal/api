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
    expect(function (): void {
        // Long parallel+coverage runs can leave a stale pooled connection; reconnect before probing.
        DB::reconnect();
        DB::select('SELECT 1');
    })->not->toThrow(QueryException::class);
});
