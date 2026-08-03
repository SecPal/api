<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Symfony\Component\Process\Process;

it('runs the container policy contract without PostgreSQL', function (): void {
    $process = new Process(
        ['composer', 'test:container-policy'],
        dirname(__DIR__, 2),
        [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'unreachable.invalid',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'testing',
            'DB_USERNAME' => 'invalid',
            'DB_PASSWORD' => 'invalid',
        ],
    );
    $process->setTimeout(60)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
