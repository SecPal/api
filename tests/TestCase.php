<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    private static bool $postgresTestDatabasesEnsured = false;

    private static ?string $temporaryBootstrapEnvironmentFile = null;

    /**
     * @var array<string, string>|null
     */
    private static ?array $localEnvironmentValues = null;

    public static function setUpBeforeClass(): void
    {
        self::ensurePostgresTestDatabasesExist();

        parent::setUpBeforeClass();
    }

    public function createApplication(): Application
    {
        self::ensureBootstrapEnvironmentFileExists();

        /** @var Application $app */
        $app = parent::createApplication();

        return $app;
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

    /**
     * Keep legacy Sanctum test authentication helpers compatible with ability-scoped API routes.
     *
     * When no guard is specified (the common Pest `actingAs()` pattern) or when
     * 'sanctum' is explicitly requested, route through Sanctum::actingAs so tests
     * receive a transient token that carries the api-access ability required by
     * the authenticated API surface.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($guard === null || $guard === 'sanctum') {
            Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

            return $this;
        }

        return parent::actingAs($user, $guard);
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

            self::assertWritableParallelTestDatabase(
                $candidate,
                self::parallelTestDatabaseAccess(self::connectToDatabase($candidate)),
            );
        }

        self::$postgresTestDatabasesEnsured = true;
    }

    /**
     * @param  array{current_user: string, database_owner: string, schema_owner: string, can_create: bool}  $access
     */
    protected static function assertWritableParallelTestDatabase(string $databaseName, array $access): void
    {
        if ($access['can_create']) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'PostgreSQL test database "%s" exists but the configured user "%s" cannot create tables in schema "public". Current database owner: "%s". Current schema owner: "%s". Ensure the database is owned by the configured app user or grant CREATE on schema public before running the test suite.',
            $databaseName,
            $access['current_user'],
            $access['database_owner'],
            $access['schema_owner'],
        ));
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
        $configuredDatabase = self::environmentValue('DB_DATABASE', 'postgres');
        $maintenanceDatabases = array_values(array_unique([
            $configuredDatabase,
            'postgres',
            'template1',
        ]));

        foreach ($maintenanceDatabases as $maintenanceDatabase) {
            try {
                return self::connectToDatabase($maintenanceDatabase);
            } catch (\PDOException $exception) {
                continue;
            }
        }

        throw new \RuntimeException('Unable to connect to a PostgreSQL maintenance database for test bootstrap.');
    }

    private static function connectToDatabase(string $databaseName): \PDO
    {
        $host = self::environmentValue('DB_HOST', '127.0.0.1');
        $port = self::environmentValue('DB_PORT', '5432');
        $username = self::environmentValue('DB_USERNAME', 'postgres');
        $password = self::environmentValue('DB_PASSWORD', '');

        return new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $databaseName),
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @return array{current_user: string, database_owner: string, schema_owner: string, can_create: bool}
     */
    private static function parallelTestDatabaseAccess(\PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT current_user AS current_user, pg_catalog.pg_get_userbyid(database_row.datdba) AS database_owner, pg_catalog.pg_get_userbyid(namespace_row.nspowner) AS schema_owner, has_schema_privilege(current_user, namespace_row.nspname, 'CREATE') AS can_create FROM pg_database AS database_row JOIN pg_namespace AS namespace_row ON namespace_row.nspname = 'public' WHERE database_row.datname = current_database()"
        );

        /** @var array{current_user?: mixed, database_owner?: mixed, schema_owner?: mixed, can_create?: mixed}|false $access */
        $access = $statement->fetch(\PDO::FETCH_ASSOC);

        if (! is_array($access)) {
            throw new \RuntimeException('Unable to determine PostgreSQL test database access details for schema validation.');
        }

        return [
            'current_user' => (string) ($access['current_user'] ?? ''),
            'database_owner' => (string) ($access['database_owner'] ?? ''),
            'schema_owner' => (string) ($access['schema_owner'] ?? ''),
            'can_create' => in_array($access['can_create'] ?? false, [true, 1, '1', 't', 'true'], true),
        ];
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

    protected static function bootstrapEnvironmentPath(): string
    {
        return dirname(__DIR__);
    }

    protected static function ensureBootstrapEnvironmentFileExists(): void
    {
        $environmentPath = static::bootstrapEnvironmentPath();
        $defaultEnvironmentFile = $environmentPath.'/.env';

        if (self::$temporaryBootstrapEnvironmentFile !== null) {
            if (self::$temporaryBootstrapEnvironmentFile === $defaultEnvironmentFile && is_file($defaultEnvironmentFile)) {
                return;
            }

            self::cleanupBootstrapEnvironmentFile();
        }

        if (is_file($defaultEnvironmentFile)) {
            return;
        }

        $stubContents = "# Temporary test bootstrap stub to avoid phpdotenv missing-file warnings in isolated worktrees\n";

        if (file_put_contents($defaultEnvironmentFile, $stubContents) === false) {
            throw new \RuntimeException('Unable to create temporary test environment file at: '.$defaultEnvironmentFile);
        }

        self::$temporaryBootstrapEnvironmentFile = $defaultEnvironmentFile;

        register_shutdown_function(static function (): void {
            self::cleanupBootstrapEnvironmentFile();
        });
    }

    protected static function cleanupBootstrapEnvironmentFile(): void
    {
        if (self::$temporaryBootstrapEnvironmentFile === null) {
            return;
        }

        if (is_file(self::$temporaryBootstrapEnvironmentFile)) {
            unlink(self::$temporaryBootstrapEnvironmentFile);
        }

        self::$temporaryBootstrapEnvironmentFile = null;
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
