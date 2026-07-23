<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('keeps setup and development scripts independent of untracked Node assets', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $setupScripts = implode("\n", $composer['scripts']['setup']);
    $developmentScripts = implode("\n", $composer['scripts']['dev']);

    expect($setupScripts)
        ->not->toContain('npm')
        ->not->toContain('npx')
        ->and($developmentScripts)
        ->not->toContain('npm')
        ->not->toContain('npx');
});

it('keeps the PHP development services running together', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $developmentScripts = implode("\n", $composer['scripts']['dev']);
    $developmentSupervisor = (string) file_get_contents(base_path('scripts/dev.php'));

    expect($developmentScripts)
        ->toContain('@php scripts/dev.php')
        ->and($developmentSupervisor)
        ->toContain("[\$php, 'artisan', 'serve']")
        ->toContain("[\$php, 'artisan', 'queue:listen', '--tries=1']")
        ->toContain("[\$php, 'artisan', 'pail', '--timeout=0']");
});

it('runs the complete test suite with the supported parallel and serial split', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $testScripts = $composer['scripts']['test'];

    expect($testScripts[0] ?? null)
        ->toBe('Composer\\Config::disableProcessTimeout')
        ->and($testScripts)
        ->toContain('@php artisan test --parallel --exclude-group=serial')
        ->toContain('@php artisan test --group=serial')
        ->not->toContain('@php artisan test');
});

it('describes the PHP development services without starting them', function (): void {
    $process = new Process([PHP_BINARY, base_path('scripts/dev.php'), '--help'], base_path());
    $process->setTimeout(2);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())
        ->toContain('Laravel development server')
        ->toContain('queue worker')
        ->toContain('log viewer');
});

it('describes the PHP development services without installed dependencies', function (): void {
    $temporaryDirectory = storage_path('framework/testing/'.Str::uuid());
    $temporaryScript = $temporaryDirectory.'/scripts/dev.php';
    mkdir(dirname($temporaryScript), 0755, true);
    copy(base_path('scripts/dev.php'), $temporaryScript);

    try {
        $process = new Process([PHP_BINARY, $temporaryScript, '--help'], $temporaryDirectory);
        $process->setTimeout(2);
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getOutput())->toContain('Laravel development server');
    } finally {
        unlink($temporaryScript);
        rmdir(dirname($temporaryScript));
        rmdir($temporaryDirectory);
    }
});

it('reports missing dependencies before starting the PHP development services', function (): void {
    $temporaryDirectory = storage_path('framework/testing/'.Str::uuid());
    $temporaryScript = $temporaryDirectory.'/scripts/dev.php';
    mkdir(dirname($temporaryScript), 0755, true);
    copy(base_path('scripts/dev.php'), $temporaryScript);

    try {
        $process = new Process([PHP_BINARY, $temporaryScript], $temporaryDirectory);
        $process->setTimeout(2);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Dependencies are unavailable. Run composer install.');
    } finally {
        unlink($temporaryScript);
        rmdir(dirname($temporaryScript));
        rmdir($temporaryDirectory);
    }
});
