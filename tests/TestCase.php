<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    private static bool $postgresTestDatabasesEnsured = false;

    /**
     * @var array<string, string>|null
     */
    private static ?array $localEnvironmentValues = null;

    public static function setUpBeforeClass(): void
    {
        self::ensurePostgresTestDatabasesExist();

        parent::setUpBeforeClass();
    }

    /**
     * Setup method runs before each test.
     * Laravel's ParallelTesting automatically handles database separation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent real network calls to HIBP so Password::uncompromised() always
        // passes for test passwords without coupling tests to an external service.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        Cache::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Laravel's RefreshDatabase trait automatically uses parallel test databases
        // when running with --parallel flag. Database naming convention:
        // Base: "testing" -> Parallel workers: "testing_test_1", "testing_test_2", etc.
        // No additional configuration needed - it's handled by Laravel automatically.
    }

    private static function ensurePostgresTestDatabasesExist(): void
    {
        if (self::$postgresTestDatabasesEnsured) {
            return;
        }

        $appEnvironment = getenv('APP_ENV');
        $databaseConnection = getenv('DB_CONNECTION');

        if ($appEnvironment !== 'testing' || $databaseConnection !== 'pgsql') {
            self::$postgresTestDatabasesEnsured = true;

            return;
        }

        $databaseName = getenv('DB_DATABASE') ?: 'testing';

        foreach (self::requiredTestDatabaseNames($databaseName) as $candidate) {
            self::assertValidDatabaseName($candidate);
        }

        $pdo = self::connectToMaintenanceDatabase();

        foreach (self::requiredTestDatabaseNames($databaseName) as $candidate) {
            $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
            $statement->execute(['database' => $candidate]);

            if ($statement->fetchColumn() === false) {
                try {
                    $pdo->exec(sprintf('CREATE DATABASE "%s"', $candidate));
                } catch (\PDOException $exception) {
                    throw new \RuntimeException(
                        sprintf(
                            'PostgreSQL test database "%s" is missing and the configured user cannot create it. Create the database manually or grant CREATEDB before running the test suite.',
                            $candidate,
                        ),
                        previous: $exception,
                    );
                }
            }
        }

        self::$postgresTestDatabasesEnsured = true;
    }

    /**
     * @return list<string>
     */
    private static function requiredTestDatabaseNames(string $databaseName): array
    {
        $testToken = getenv('TEST_TOKEN');

        if (is_string($testToken) && preg_match('/\A\d+\z/', $testToken) === 1) {
            return [$databaseName.'_test_'.$testToken];
        }

        return [$databaseName];
    }

    private static function assertValidDatabaseName(string $databaseName): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $databaseName)) {
            throw new \RuntimeException('Invalid PostgreSQL test database name: '.$databaseName);
        }
    }

    private static function connectToMaintenanceDatabase(): \PDO
    {
        $host = self::environmentValue('DB_HOST', '127.0.0.1');
        $port = self::environmentValue('DB_PORT', '5432');
        $username = self::environmentValue('DB_USERNAME', 'postgres');
        $password = self::environmentValue('DB_PASSWORD', '');

        $configuredDatabase = self::environmentValue('DB_DATABASE', 'postgres');
        $maintenanceDatabases = array_values(array_unique([
            $configuredDatabase,
            'postgres',
            'template1',
        ]));

        foreach ($maintenanceDatabases as $maintenanceDatabase) {
            try {
                $pdo = new \PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $maintenanceDatabase),
                    $username,
                    $password,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
                );

                return $pdo;
            } catch (\PDOException $exception) {
                continue;
            }
        }

        throw new \RuntimeException('Unable to connect to a PostgreSQL maintenance database for test bootstrap.');
    }

    private static function environmentValue(string $key, string $default): string
    {
        $value = getenv($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $localValue = self::localEnvironmentValues()[$key] ?? null;

        if (is_string($localValue) && $localValue !== '') {
            return $localValue;
        }

        return $default;
    }

    /**
     * @return array<string, string>
     */
    private static function localEnvironmentValues(): array
    {
        if (self::$localEnvironmentValues !== null) {
            return self::$localEnvironmentValues;
        }

        $environmentFile = dirname(__DIR__).'/.env';

        if (! is_file($environmentFile)) {
            self::$localEnvironmentValues = [];

            return self::$localEnvironmentValues;
        }

        $values = [];

        foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#') || ! str_contains($trimmedLine, '=')) {
                continue;
            }

            [$name, $rawValue] = explode('=', $trimmedLine, 2);
            $values[trim($name)] = trim($rawValue, " \t\n\r\0\x0B\"'");
        }

        self::$localEnvironmentValues = $values;

        return self::$localEnvironmentValues;
    }
}
