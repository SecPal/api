<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

test('customer legal entity migration preserves the approved existing-data strategy', function (): void {
    $migration = file_get_contents(base_path('database/migrations/2026_07_14_120000_add_legal_entity_id_to_customers_table.php'));

    expect($migration)->toContain("DB::table('customers')->exists()")
        ->and($migration)->toContain('Cannot add customers.legal_entity_id while customers exist.')
        ->and($migration)->toContain('US-001 requires a product-approved deterministic tenant-consistent backfill')
        ->and($migration)->not->toContain('default(');
});
