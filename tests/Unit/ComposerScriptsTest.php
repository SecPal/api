<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

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
