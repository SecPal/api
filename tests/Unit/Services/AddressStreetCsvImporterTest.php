<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\AddressDataImport;
use App\Services\AddressData\AddressStreetCsvImporter;

test('CSV importer accepts exact header and preserves postal codes with leading zeros', function (): void {
    $path = base_path('tests/fixtures/address_data/sample_streets.csv');
    $import = new AddressDataImport(['id' => 0]);
    $importer = new AddressStreetCsvImporter;

    $count = $importer->importFile($path, $import, true);

    expect($count)->toBe(3);
});

test('CSV importer error for wrong column count includes row number, expected and actual counts, and a row preview', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'addr_street_csv_');
    expect($path)->not->toBeFalse();
    $header = implode(',', AddressStreetCsvImporter::EXPECTED_HEADER);
    file_put_contents($path, $header."\n".'Only,Two,Cols'."\n");

    $import = new AddressDataImport(['id' => 0]);
    $importer = new AddressStreetCsvImporter;

    $threw = false;
    try {
        $importer->importFile($path, $import, true);
    } catch (InvalidArgumentException $e) {
        $threw = true;
        expect($e->getMessage())
            ->toContain('CSV data row 1')
            ->and($e->getMessage())->toContain('expected '.count(AddressStreetCsvImporter::EXPECTED_HEADER).', got 3')
            ->and($e->getMessage())->toContain('Row preview:')
            ->and($e->getMessage())->toContain('Only,Two,Cols');
    } finally {
        unlink($path);
    }

    expect($threw)->toBeTrue();
});
