<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Tests\TestCase;

final class TestCaseBootstrapEnvironmentProbe extends TestCase
{
    private static ?string $probeEnvironmentPath = null;

    /**
     * @param  array{current_user: string, database_owner: string, schema_owner: string, can_create: bool}  $access
     */
    public static function assertWritableParallelTestDatabase(string $databaseName, array $access): void
    {
        parent::assertWritableParallelTestDatabase($databaseName, $access);
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

    public static function bootstrapEnvironmentFileName(): string
    {
        return parent::bootstrapEnvironmentFileName();
    }

    public static function normalizeBootstrapApplication(Application $app): void
    {
        parent::normalizeApplicationConfiguration($app);
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
