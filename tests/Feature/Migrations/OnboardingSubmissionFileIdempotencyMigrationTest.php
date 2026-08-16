<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('onboarding upload idempotency uniqueness excludes uploads without a key', function (): void {
    $indexDefinitions = array_map(
        static fn (object $row): string => (string) $row->indexdef,
        DB::select(<<<'SQL'
            SELECT indexdef
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename = 'onboarding_submission_files'
              AND indexname = 'onboarding_submission_files_idempotency_unique'
            SQL),
    );

    expect($indexDefinitions)->toHaveCount(1)
        ->and(strtolower($indexDefinitions[0]))
        ->toContain('where (idempotency_key is not null)');
});
