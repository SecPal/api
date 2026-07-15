<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Testing\Concerns\TestDatabases;
use Tests\TestCase;

final class TestCaseBootstrapEnvironmentProbe extends TestCase
{
    use TestDatabases {
        testDatabase as private frameworkParallelTestDatabaseName;
    }

    private static ?string $probeEnvironmentPath = null;

    /**
     * @param  array{
     *     current_user: string,
     *     database_owner: string,
     *     public_schema_owner: string,
     *     target_schema_owner: string|null,
     *     can_create_public_schema: bool,
     *     can_create_target_schema: bool,
     *     can_use_target_schema: bool,
     *     can_create_schema: bool,
     *     target_schema_exists: bool
     * }  $access
     */
    public static function assertWritableParallelTestDatabase(string $databaseName, string $schemaName, array $access): void
    {
        parent::assertWritableParallelTestDatabase($databaseName, $schemaName, $access);
    }

    public static function assertValidSchemaName(string $schemaName): void
    {
        parent::assertValidSchemaName($schemaName);
    }

    /**
     * @param  array{target_schema_exists: bool}  $access
     */
    public static function shouldCreateIsolatedTestSchema(array $access): bool
    {
        return parent::shouldCreateIsolatedTestSchema($access);
    }

    public static function useProbeEnvironmentPath(string $path): void
    {
        self::$probeEnvironmentPath = $path;
    }

    public static function createBootstrapEnvironmentStub(): void
    {
        parent::prepareBootstrapEnvironment();
    }

    public static function applyPhpUnitEnvironmentOverrides(): void
    {
        parent::applyPhpUnitEnvironmentOverrides();
    }

    public static function prepareBootstrapEnvironment(): void
    {
        parent::prepareBootstrapEnvironment();
    }

    public static function createBootstrapApplication(): Application
    {
        return (new self('createApplication'))->createApplication();
    }

    public static function bootstrapEnvironmentFileName(): string
    {
        return parent::bootstrapEnvironmentFileName();
    }

    public static function bootstrapEnvironmentLockFilePath(): string
    {
        return parent::bootstrapEnvironmentLockFilePath();
    }

    public static function normalizeBootstrapApplication(Application $app): void
    {
        parent::normalizeApplicationConfiguration($app);
    }

    public static function isolatedTestSchemaName(): string
    {
        return parent::isolatedTestSchemaName();
    }

    public static function isolatedTestDatabaseName(string $databaseName): string
    {
        return parent::isolatedTestDatabaseName($databaseName);
    }

    public static function effectiveIsolatedTestSchemaName(): string
    {
        return parent::effectiveIsolatedTestSchemaName();
    }

    public static function effectiveIsolatedTestDatabaseName(): string
    {
        return parent::effectiveIsolatedTestDatabaseName();
    }

    public function parallelTestDatabaseName(string $databaseName): string
    {
        return $this->frameworkParallelTestDatabaseName($databaseName);
    }

    public static function resetParallelTestDatabaseName(): void
    {
        self::$originalDatabaseName = null;
    }

    public static function expectedTestAppKey(): string
    {
        return parent::expectedTestAppKey();
    }

    public static function removeBootstrapEnvironmentStub(): void
    {
        parent::cleanupBootstrapEnvironmentFile();
    }

    public static function clearProbeEnvironmentPath(): void
    {
        self::$probeEnvironmentPath = null;
    }

    public static function resetBootstrapEnvironmentState(): void
    {
        parent::resetBootstrapEnvironmentState();
    }

    protected static function bootstrapEnvironmentPath(): string
    {
        return self::$probeEnvironmentPath ?? parent::bootstrapEnvironmentPath();
    }
}
