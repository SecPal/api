<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

it('keeps setup and development scripts independent of untracked Node assets', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])
        ->not->toContain('npm install')
        ->not->toContain('npm run build');

    expect($composer['scripts']['dev'])
        ->not->toContain('npm run dev')
        ->not->toContain('npx concurrently');
});
