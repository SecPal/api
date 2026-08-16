<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

test('db-backed address-data suites use the scoped lifecycle test case', function (): void {
    foreach ([
        'tests/Unit/Services/AddressSuggestionServiceTest.php',
        'tests/Unit/Services/AddressDataImportServiceTest.php',
    ] as $path) {
        $contents = file_get_contents(base_path($path));

        expect($contents)->not->toBeFalse()
            ->and($contents)->toContain('uses(ResetsRefreshDatabaseStateForAddressData::class)');
    }
});
