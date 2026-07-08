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

test('dependabot keeps hyphen separated branch names for readable update branches', function (): void {
    $contents = dependabotConfigContents();

    expect($contents)
        ->toContain('separator: "-"')
        ->not->toContain('separator: "/"');
});

test('dependabot groups shared github workflow updates under stable readable identifiers', function (): void {
    $contents = dependabotConfigContents();

    expect($contents)
        ->toContain('shared-github-workflows:')
        ->toContain('shared-github-workflows-security:')
        ->toContain('patterns:')
        ->toContain('- "SecPal/.github/.github/workflows/*"')
        ->toContain('applies-to: "security-updates"');
});
