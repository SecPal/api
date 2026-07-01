<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

function phpCiWorkflowContents(): string
{
    $contents = file_get_contents(base_path('.github/workflows/php-ci.yml'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('php ci excludes serial tests from the parallel pest run and executes them separately', function (): void {
    $contents = phpCiWorkflowContents();

    expect($contents)
        ->toContain('php artisan test --parallel --exclude-group=serial --coverage-clover coverage.xml')
        ->toContain('php artisan test --group=serial --coverage-clover coverage-serial.xml')
        ->toContain('files: ./coverage.xml,./coverage-serial.xml');
});
