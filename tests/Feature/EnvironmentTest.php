<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

describe('Environment Configuration', function () {
    it('has database connection configured', function () {
        $connection = config('database.default');
        expect($connection)->toBeIn(['pgsql', 'sqlite']); // Tests use SQLite by default
        expect(config("database.connections.{$connection}"))->not->toBeEmpty();
        expect(config("database.connections.{$connection}.driver"))->toBe($connection);
    });

    it('has APP_KEY set', function () {
        $appKey = config('app.key');
        expect($appKey)->not->toBeNull()
            ->and($appKey)->not->toBe('')
            ->and(strlen(base64_decode(substr($appKey, 7))))->toBeGreaterThanOrEqual(32);
    });

    it('has KEK_PATH environment variable', function () {
        expect(env('KEK_PATH'))->not->toBeNull()
            ->and(env('KEK_PATH'))->toBeString();
    });

    it('can load environment variables', function () {
        expect(env('APP_NAME'))->toBe('SecPal API')
            ->and(env('APP_ENV'))->toBeIn(['local', 'testing', 'staging', 'production'])
            ->and(env('APP_DEBUG'))->toBeIn([true, false, 'true', 'false', '1', '0']);
    });
});
