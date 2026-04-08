<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support;

use Tests\TestCase;

final class TestCaseBootstrapEnvironmentProbe extends TestCase
{
    private static ?string $probeEnvironmentPath = null;

    public static function useProbeEnvironmentPath(string $path): void
    {
        self::$probeEnvironmentPath = $path;
    }

    public static function createBootstrapEnvironmentStub(): void
    {
        parent::ensureBootstrapEnvironmentFileExists();
    }

    public static function removeBootstrapEnvironmentStub(): void
    {
        parent::cleanupBootstrapEnvironmentFile();
    }

    public static function clearProbeEnvironmentPath(): void
    {
        self::$probeEnvironmentPath = null;
    }

    protected static function bootstrapEnvironmentPath(): string
    {
        return self::$probeEnvironmentPath ?? parent::bootstrapEnvironmentPath();
    }
}
