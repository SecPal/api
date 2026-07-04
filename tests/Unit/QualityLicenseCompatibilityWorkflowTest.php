<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

function qualityWorkflowContents(): string
{
    $contents = file_get_contents(base_path('.github/workflows/quality.yml'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('quality workflow allows the secpal attribution license reference', function (): void {
    $contents = qualityWorkflowContents();

    expect($contents)
        ->toContain('LicenseRef-SecPal-Attribution')
        ->not->toContain('reusable-license-compatibility.yml');
});
