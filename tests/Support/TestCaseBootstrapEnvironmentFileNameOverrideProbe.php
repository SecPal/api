<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Tests\TestCase;

final class TestCaseBootstrapEnvironmentFileNameOverrideProbe extends TestCase
{
    private static ?string $probeEnvironmentFileName = null;

    public static function useProbeEnvironmentFileName(string $fileName): void
    {
        self::$probeEnvironmentFileName = $fileName;
    }

    public static function clearProbeEnvironmentFileName(): void
    {
        self::$probeEnvironmentFileName = null;
    }

    public static function createBootstrapEnvironmentStub(): void
    {
        parent::prepareBootstrapEnvironment();
    }

    public static function removeBootstrapEnvironmentStub(): void
    {
        parent::cleanupBootstrapEnvironmentFile();
    }

    public static function resetBootstrapEnvironmentState(): void
    {
        parent::resetBootstrapEnvironmentState();
    }

    public static function bootstrapEnvironmentFilePath(): string
    {
        return parent::bootstrapEnvironmentFilePath();
    }

    public static function createBootstrapApplication(): Application
    {
        return (new self('createApplication'))->createApplication();
    }

    protected static function bootstrapEnvironmentFileName(): string
    {
        return self::$probeEnvironmentFileName ?? parent::bootstrapEnvironmentFileName();
    }
}
