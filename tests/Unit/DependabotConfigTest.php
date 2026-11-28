<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

function dependabotConfigContents(): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/.github/dependabot.yml');

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('dependabot keeps hyphen separated branch names for readable update branches', function (): void {
    $contents = dependabotConfigContents();

    expect($contents)
        ->toContain('separator: "-"')
        ->not->toContain('separator: "/"');
});
