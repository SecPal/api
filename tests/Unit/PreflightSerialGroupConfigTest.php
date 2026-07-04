<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

function preflightScriptContents(): string
{
    $contents = file_get_contents(base_path('scripts/preflight.sh'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('preflight excludes serial tests from the parallel run and executes them separately', function (): void {
    $contents = preflightScriptContents();

    expect($contents)
        ->toContain('${CMD_PREFIX} php artisan test --parallel --exclude-group=serial || TEST_EXIT=$?')
        ->toContain('${CMD_PREFIX} php artisan test --group=serial || TEST_EXIT=$?');
});
