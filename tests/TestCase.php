<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    private const TEST_APP_KEY = 'base64:nRWNo2CgugcDYn5VJsEzigv2nowyJLSArqfRhlB+USo=';

    private const TEST_BOOTSTRAP_ENVIRONMENT_FILE = '.env.testing.bootstrap';

    /**
     * @var list<string>
     */
    private const LOCAL_ENV_PASSTHROUGH_KEYS = [
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    private static bool $postgresTestDatabasesEnsured = false;

    private static bool $bootstrapEnvironmentCleanupRegistered = false;

    /**
     * @var array<string, string>|null
     */
    private static ?array $phpUnitEnvironmentOverrides = null;

    private static ?string $temporaryBootstrapEnvironmentFile = null;

    /**
     * @var array<string, string>|null
     */
    private static ?array $localEnvironmentValues = null;

    private static ?string $localEnvironmentValuesPath = null;

    public static function setUpBeforeClass(): void
    {
        self::prepareBootstrapEnvironment();
        self::ensurePostgresTestDatabasesExist();

        parent::setUpBeforeClass();
    }

    public function createApplication(): Application
    {
        self::prepareBootstrapEnvironment();

        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->loadEnvironmentFrom(static::bootstrapEnvironmentFileName());
        $app->make(Kernel::class)->bootstrap();
        self::normalizeApplicationConfiguration($app);

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

        if ($value !== false) {
            return $value;
        }

        $serverValue = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if (is_string($serverValue)) {
            return $serverValue;
        }

        return $default;
    }

    protected static function prepareBootstrapEnvironment(): void
    {
        self::clearInheritedEnvironmentValues();
        self::applyPhpUnitEnvironmentOverrides();
        self::applyTestEnvironmentDefaults();
        self::applyLocalEnvironmentPassthroughs();
        self::ensureBootstrapEnvironmentFileExists();
    }

    protected static function clearInheritedEnvironmentValues(): void
    {
        $keysToClear = ['APP_KEY' => true];

        foreach (array_keys(self::localEnvironmentValues()) as $name) {
            if (in_array($name, self::LOCAL_ENV_PASSTHROUGH_KEYS, true)) {
                continue;
            }

            $keysToClear[$name] = true;
        }

        $processEnv = is_array(getenv()) ? getenv() : [];

        foreach (array_keys($_ENV + $_SERVER + $processEnv) as $name) {
            if (! str_starts_with((string) $name, 'BOOTSTRAP_')) {
                continue;
            }

            $keysToClear[$name] = true;
        }

        foreach (array_keys($keysToClear) as $name) {
            self::unsetEnvironmentValue($name);
        }
    }

    protected static function applyPhpUnitEnvironmentOverrides(): void
    {
        foreach (self::phpUnitEnvironmentOverrides() as $name => $value) {
            self::setEnvironmentValue($name, $value);

            if ($name === 'DB_DATABASE') {
                self::setEnvironmentValue('SECPAL_TEST_DATABASE', $value);
            }
        }
    }

    protected static function applyLocalEnvironmentPassthroughs(): void
    {
        foreach (self::localEnvironmentPassthroughValues() as $name => $value) {
            if (! self::environmentVariableIsMissing($name)) {
                continue;
            }

            self::setEnvironmentValue($name, $value);
        }
    }

    protected static function applyTestEnvironmentDefaults(): void
    {
        self::setEnvironmentValue('APP_KEY', self::TEST_APP_KEY);
    }

    protected static function expectedTestAppKey(): string
    {
        return self::TEST_APP_KEY;
    }

    protected static function normalizeApplicationConfiguration(Application $app): void
    {
        $databaseConnection = self::phpUnitEnvironmentOverrides()['DB_CONNECTION'] ?? null;
        $databaseName = self::phpUnitEnvironmentOverrides()['DB_DATABASE'] ?? null;

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
    }

    protected static function bootstrapEnvironmentPath(): string
    {
        return dirname(__DIR__);
    }

    protected static function bootstrapEnvironmentFileName(): string
    {
        return self::TEST_BOOTSTRAP_ENVIRONMENT_FILE;
    }

    protected static function bootstrapEnvironmentFilePath(): string
    {
        return rtrim(static::bootstrapEnvironmentPath(), '/').'/'.static::bootstrapEnvironmentFileName();
    }

    protected static function bootstrapEnvironmentLockFilePath(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'secpal-api-bootstrap-env-'
            .sha1(static::bootstrapEnvironmentFilePath())
            .'.lock';
    }

    protected static function ensureBootstrapEnvironmentFileExists(): void
    {
        $bootstrapEnvironmentFile = static::bootstrapEnvironmentFilePath();

        if (self::$temporaryBootstrapEnvironmentFile !== null && self::$temporaryBootstrapEnvironmentFile !== $bootstrapEnvironmentFile) {
            self::cleanupBootstrapEnvironmentFile();
        }

        $stubContents = self::bootstrapEnvironmentFileContents();

        self::synchronizeBootstrapEnvironmentWrite($bootstrapEnvironmentFile, $stubContents);

        self::$temporaryBootstrapEnvironmentFile = $bootstrapEnvironmentFile;

        if (! self::$bootstrapEnvironmentCleanupRegistered) {
            register_shutdown_function(static function (): void {
                self::cleanupBootstrapEnvironmentFile();
            });

            self::$bootstrapEnvironmentCleanupRegistered = true;
        }
    }

    private static function synchronizeBootstrapEnvironmentWrite(string $bootstrapEnvironmentFile, string $stubContents): void
    {
        $lockHandle = fopen(static::bootstrapEnvironmentLockFilePath(), 'c+');

        if ($lockHandle === false) {
            throw new \RuntimeException('Unable to open bootstrap environment lock file for: '.$bootstrapEnvironmentFile);
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock bootstrap environment file for: '.$bootstrapEnvironmentFile);
            }

            self::publishBootstrapEnvironmentFileAtomically($bootstrapEnvironmentFile, $stubContents);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private static function publishBootstrapEnvironmentFileAtomically(string $bootstrapEnvironmentFile, string $stubContents): void
    {
        $temporaryBootstrapEnvironmentFile = tempnam(
            dirname($bootstrapEnvironmentFile),
            basename($bootstrapEnvironmentFile).'.',
        );

        if ($temporaryBootstrapEnvironmentFile === false) {
            throw new \RuntimeException('Unable to allocate temporary test environment file for: '.$bootstrapEnvironmentFile);
        }

        try {
            if (file_put_contents($temporaryBootstrapEnvironmentFile, $stubContents, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to create temporary test environment file at: '.$bootstrapEnvironmentFile);
            }

            if (! chmod($temporaryBootstrapEnvironmentFile, 0600)) {
                throw new \RuntimeException('Unable to restrict permissions on temporary test environment file at: '.$bootstrapEnvironmentFile);
            }

            if (! rename($temporaryBootstrapEnvironmentFile, $bootstrapEnvironmentFile)) {
                throw new \RuntimeException('Unable to publish temporary test environment file at: '.$bootstrapEnvironmentFile);
            }
        } catch (\Throwable $exception) {
            if (is_file($temporaryBootstrapEnvironmentFile)) {
                unlink($temporaryBootstrapEnvironmentFile);
            }

            throw $exception;
        }
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

    protected static function resetBootstrapEnvironmentState(): void
    {
        self::$localEnvironmentValues = null;
        self::$localEnvironmentValuesPath = null;
        self::$temporaryBootstrapEnvironmentFile = null;
        self::$bootstrapEnvironmentCleanupRegistered = false;
    }

    /**
     * @return array<string, string>
     */
    private static function localEnvironmentValues(): array
    {
        $environmentFile = rtrim(static::bootstrapEnvironmentPath(), '/').'/.env';

        if (self::$localEnvironmentValues !== null && self::$localEnvironmentValuesPath === $environmentFile) {
            return self::$localEnvironmentValues;
        }

        self::$localEnvironmentValuesPath = $environmentFile;

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
            $name = trim($name);

            $values[$name] = trim($rawValue, " \t\n\r\0\x0B\"'");
        }

        self::$localEnvironmentValues = $values;

        return self::$localEnvironmentValues;
    }

    /**
     * @return array<string, string>
     */
    private static function localEnvironmentPassthroughValues(): array
    {
        $allowedKeys = array_flip(self::LOCAL_ENV_PASSTHROUGH_KEYS);

        return array_filter(
            self::localEnvironmentValues(),
            static fn (string $name): bool => isset($allowedKeys[$name]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function bootstrapEnvironmentVariables(): array
    {
        $variables = self::phpUnitEnvironmentOverrides();
        $variables['SECPAL_TEST_DATABASE'] = self::environmentValue(
            'SECPAL_TEST_DATABASE',
            $variables['DB_DATABASE'] ?? 'testing',
        );
        $variables['APP_KEY'] = self::environmentValue('APP_KEY', self::TEST_APP_KEY);

        foreach (self::LOCAL_ENV_PASSTHROUGH_KEYS as $name) {
            if (self::environmentVariableIsMissing($name)) {
                continue;
            }

            $variables[$name] = self::environmentValue($name, '');
        }

        return $variables;
    }

    private static function bootstrapEnvironmentFileContents(): string
    {
        $lines = ['# Temporary test bootstrap env file generated for isolated PHPUnit runs'];

        foreach (self::bootstrapEnvironmentVariables() as $name => $value) {
            $lines[] = sprintf('%s="%s"', $name, addcslashes($value, "\\\"\n\r$"));
        }

        return implode("\n", $lines)."\n";
    }

    private static function environmentVariableIsMissing(string $name): bool
    {
        if (getenv($name) !== false) {
            return false;
        }

        return ! array_key_exists($name, $_ENV) && ! array_key_exists($name, $_SERVER);
    }

    private static function setEnvironmentValue(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private static function unsetEnvironmentValue(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    /**
     * @return array<string, string>
     */
    private static function phpUnitEnvironmentOverrides(): array
    {
        if (self::$phpUnitEnvironmentOverrides !== null) {
            return self::$phpUnitEnvironmentOverrides;
        }

        $configurationPath = dirname(__DIR__).'/phpunit.xml';

        if (! is_file($configurationPath)) {
            self::$phpUnitEnvironmentOverrides = [];

            return self::$phpUnitEnvironmentOverrides;
        }

        $configuration = simplexml_load_file($configurationPath);

        if (! $configuration instanceof \SimpleXMLElement) {
            self::$phpUnitEnvironmentOverrides = [];

            return self::$phpUnitEnvironmentOverrides;
        }

        $overrides = [];

        /** @var \SimpleXMLElement[]|false $envNodes */
        $envNodes = $configuration->xpath('/phpunit/php/env');

        if ($envNodes !== false) {
            foreach ($envNodes as $envNode) {
                $name = (string) ($envNode['name'] ?? '');
                $value = (string) ($envNode['value'] ?? '');
                $force = strtolower((string) ($envNode['force'] ?? 'false'));

                if ($name === '' || ! in_array($force, ['1', 'true', 'yes'], true)) {
                    continue;
                }

                $overrides[$name] = $value;
            }
        }

        self::$phpUnitEnvironmentOverrides = $overrides;

        return self::$phpUnitEnvironmentOverrides;
    }
}
