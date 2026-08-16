<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

it('lists scheduled tasks without a database connection', function (): void {
    expect(Illuminate\Support\Facades\Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    $process = new Symfony\Component\Process\Process(
        [PHP_BINARY, 'artisan', 'schedule:list', '--json'], base_path(),
        ['APP_ENV' => 'production', 'CACHE_STORE' => 'database', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'unreachable.invalid'],
    );
    $process->setTimeout(10)->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true))->toBeArray()->not->toBeEmpty();
});
