<?php

// SPDX-FileCopyrightText: 2026 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

it('removes Secrets migrations entirely in 0.x', function () {
    $migrationFiles = glob(database_path('migrations/*.php'));

    expect($migrationFiles)->not->toBeFalse();

    $secretMigrations = array_filter(
        $migrationFiles,
        static fn (string $migrationFile): bool => str_contains(strtolower(basename($migrationFile)), 'secret'),
    );

    expect(array_values($secretMigrations))->toBeEmpty();
});
