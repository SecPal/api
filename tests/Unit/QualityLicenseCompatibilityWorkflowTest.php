<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

function qualityWorkflowContents(): string
{
    $contents = file_get_contents(base_path('.github/workflows/quality.yml'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('quality workflow delegates SPDX policy to the compatibility validator', function (): void {
    $contents = qualityWorkflowContents();

    expect($contents)
        ->toContain('scripts/check-license-compatibility.sh')
        ->toContain('reuse spdx --add-license-concluded')
        ->not->toContain('grep "LicenseInfoInFile"');
});
