<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

$configuration = @simplexml_load_file(__DIR__.'/../phpunit.xml');

if ($configuration instanceof SimpleXMLElement) {
    /** @var SimpleXMLElement[]|false $envNodes */
    $envNodes = $configuration->xpath('/phpunit/php/env');

    if ($envNodes !== false) {
        foreach ($envNodes as $envNode) {
            $name = (string) ($envNode['name'] ?? '');
            $value = (string) ($envNode['value'] ?? '');
            $force = strtolower((string) ($envNode['force'] ?? 'false'));

            if ($name === '' || ! in_array($force, ['1', 'true', 'yes'], true)) {
                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;

            if ($name === 'DB_DATABASE') {
                putenv('SECPAL_TEST_DATABASE='.$value);
                $_ENV['SECPAL_TEST_DATABASE'] = $value;
                $_SERVER['SECPAL_TEST_DATABASE'] = $value;
            }
        }
    }
}

require __DIR__.'/../vendor/autoload.php';

if (class_exists(Illuminate\Testing\ParallelRunner::class)) {
    Illuminate\Testing\ParallelRunner::resolveApplicationUsing(static function (): Illuminate\Foundation\Application {
        /** @var Illuminate\Foundation\Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $databaseConnection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null);
        $databaseName = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null);

        if (is_string($databaseConnection) && $databaseConnection !== '') {
            $app['config']->set('database.default', $databaseConnection);
        }

        if (
            is_string($databaseConnection) && $databaseConnection !== ''
            && is_string($databaseName) && $databaseName !== ''
        ) {
            $app['config']->set("database.connections.{$databaseConnection}.database", $databaseName);
            $app['config']->set("database.connections.{$databaseConnection}.url", null);
        }

        if (isset($app['db'])) {
            $app['db']->purge();
        }

        return $app;
    });
}
