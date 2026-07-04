<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Services\AddressData\AddressDataDownloader;
use App\Services\AddressData\AddressDataImportService;
use App\Services\AddressData\AddressStreetCsvImporter;
use App\Services\AddressData\AddressSuggestionService;
use Tests\Support\ResetsRefreshDatabaseStateForAddressData;

uses(ResetsRefreshDatabaseStateForAddressData::class)->group('unit', 'services', 'address-data');

test('dry run validates fixture csv without persisting import or street rows', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $service = new AddressDataImportService(
        new AddressDataDownloader,
        new AddressStreetCsvImporter,
        new AddressSuggestionService,
    );

    expect(AddressDataImport::query()->count())->toBe(0)
        ->and(AddressStreet::query()->count())->toBe(0);

    expect($service->run(
        force: false,
        dryRun: true,
        sourcePath: $fixture,
        ifEmpty: false,
        setupOnly: false,
        keepImports: 1,
    ))->toBe([
        'status' => 'dry_run',
        'message' => 'Validated CSV; 3 rows would be imported.',
    ]);

    expect(AddressDataImport::query()->count())->toBe(0)
        ->and(AddressStreet::query()->count())->toBe(0);
});
