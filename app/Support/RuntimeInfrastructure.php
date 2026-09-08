<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class RuntimeInfrastructure
{
    public const string CACHE_STORE = 'database';

    public const string DATABASE_CONNECTION = 'pgsql';

    public const string QUEUE_CONNECTION = 'database';

    public const string SESSION_DRIVER = 'database';

    public const string SCHEDULER_LOCK_STORE = 'database';

    /**
     * Laravel merges framework adapter examples into application configuration.
     * Reduce those merged matrices to the SecPal contract and bounded helpers.
     */
    public static function normalizeConfiguration(): void
    {
        self::retainKeys('database.connections', ['sqlite', self::DATABASE_CONNECTION]);
        self::retainKeys('cache.stores', ['array', self::CACHE_STORE, 'file']);
        self::retainKeys('queue.connections', ['sync', self::QUEUE_CONNECTION]);

        $database = config('database', []);

        if (is_array($database)) {
            unset($database['redis']);
            config()->set('database', $database);
        }

        $session = config('session', []);

        if (is_array($session)) {
            unset($session['files'], $session['store']);
            config()->set('session', $session);
        }
    }

    public static function assertProductionConfiguration(Application $application): void
    {
        if (! $application->environment('production')) {
            return;
        }

        self::assertConfiguredValue('database.default', self::DATABASE_CONNECTION, 'database connection');
        self::assertConfiguredValue('cache.default', self::CACHE_STORE, 'cache store');
        self::assertConfiguredValue('queue.default', self::QUEUE_CONNECTION, 'queue connection');
        self::assertConfiguredValue('session.driver', self::SESSION_DRIVER, 'session driver');

        if (config('database.connections.pgsql.sslmode') !== 'verify-full') {
            throw new RuntimeException('Production PostgreSQL requires DB_SSLMODE=verify-full.');
        }

        $trustedCa = config('database.connections.pgsql.sslrootcert');

        if (! is_string($trustedCa) || trim($trustedCa) === '') {
            throw new RuntimeException('Production PostgreSQL requires a non-empty DB_SSLROOTCERT trusted CA path.');
        }
    }

    private static function assertConfiguredValue(string $key, string $expected, string $label): void
    {
        $configured = config($key);

        if ($configured !== $expected) {
            $rendered = is_scalar($configured) ? (string) $configured : get_debug_type($configured);

            throw new RuntimeException("Unsupported production {$label} [{$rendered}]; expected [{$expected}].");
        }
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    private static function retainKeys(string $key, array $allowedKeys): void
    {
        $configured = config($key, []);

        if (! is_array($configured)) {
            config()->set($key, []);

            return;
        }

        config()->set($key, array_intersect_key($configured, array_flip($allowedKeys)));
    }
}
