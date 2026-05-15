<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Services\AddressData\AddressStreetCsvImporter;

test('CSV importer accepts exact header and preserves postal codes with leading zeros', function (): void {
    $path = base_path('tests/fixtures/address_data/sample_streets.csv');
    $import = new AddressDataImport(['id' => 0]);
    $importer = new AddressStreetCsvImporter;

    $count = $importer->importFile($path, $import, true);

    expect($count)->toBe(3);
});
