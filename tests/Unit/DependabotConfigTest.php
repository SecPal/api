<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

function dependabotConfigContents(): string
{
    $contents = file_get_contents(base_path('.github/dependabot.yml'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('dependabot keeps slash separated branch names for readable update branches', function (): void {
    $contents = dependabotConfigContents();

    expect($contents)
        ->toContain('separator: "/"')
        ->not->toContain('separator: "-"');
});
