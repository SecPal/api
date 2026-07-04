<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

Tests\Support\TestCaseBootstrapEnvironmentProbe::prepareBootstrapEnvironment();

if (class_exists(Illuminate\Testing\ParallelRunner::class)) {
    Illuminate\Testing\ParallelRunner::resolveApplicationUsing(static function (): Illuminate\Foundation\Application {
        Tests\Support\TestCaseBootstrapEnvironmentProbe::prepareBootstrapEnvironment();

        /** @var Illuminate\Foundation\Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->loadEnvironmentFrom(Tests\Support\TestCaseBootstrapEnvironmentProbe::bootstrapEnvironmentFileName());
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Tests\Support\TestCaseBootstrapEnvironmentProbe::normalizeBootstrapApplication($app);

        return $app;
    });
}
