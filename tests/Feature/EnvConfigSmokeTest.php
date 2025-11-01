<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 SecPal
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

describe('Environment Configuration Smoke Tests', function (): void {
    test('essential config values are set', function (): void {
        expect(Config::get('app.env'))->not->toBeNull();
        expect(Config::get('database.default'))->not->toBeNull();
    });

    test('application config is loaded correctly', function (): void {
        expect(Config::get('app.name'))->not->toBeNull();
        expect(Config::get('app.key'))->not->toBeNull();
    });

    test('database connection is working', function (): void {
        expect(fn () => DB::connection()->getPdo())
            ->not->toThrow(Exception::class);
    });
});
