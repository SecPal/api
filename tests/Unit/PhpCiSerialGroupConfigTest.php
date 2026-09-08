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

test('php ci uses the precreated worker database public schema for parallel pest', function (): void {
    $contents = phpCiWorkflowContents();

    expect($contents)
        ->toContain('SECPAL_TEST_SCHEMA: public')
        ->toContain('php artisan test --parallel --exclude-group=serial --coverage-clover coverage.xml');
});

test('php ci pins its current PostgreSQL 18 service fixture', function (): void {
    expect(phpCiWorkflowContents())
        ->toContain('image: postgres:18-bookworm@sha256:1c59e2c3c818eaa0f0628f695b36e7c9e362d6b219b36a54a32df645cbd7e1af')
        ->not->toMatch('/image: postgres:(?:16|17)(?:[-@:]|$)/');
});
