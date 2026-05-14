<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Services\AddressData\AddressDataDownloader;
use App\Services\AddressData\AddressDataImportService;
use App\Services\AddressData\AddressStreetCsvImporter;
use App\Services\AddressData\AddressSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('unit', 'services', 'address-data');

test('dry run passes sentinel import id to csv importer', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $downloader = Mockery::mock(AddressDataDownloader::class);
    $csvImporter = Mockery::mock(AddressStreetCsvImporter::class);
    $suggestions = Mockery::mock(AddressSuggestionService::class);

    $downloader->shouldReceive('download')
        ->once()
        ->with(config('address_data.source_url'), $fixture, null)
        ->andReturn([
            'path' => $fixture,
            'sha256' => str_repeat('a', 64),
            'etag' => null,
            'last_modified' => null,
        ]);

    $csvImporter->shouldReceive('importFile')
        ->once()
        ->withArgs(function (string $path, AddressDataImport $import, bool $dryRun, mixed $onProgress) use ($fixture): bool {
            expect($path)->toBe($fixture);
            expect($import->id)->toBe(0);
            expect($dryRun)->toBeTrue();
            expect($onProgress)->toBeNull();

            return true;
        })
        ->andReturn(3);

    $service = new AddressDataImportService($downloader, $csvImporter, $suggestions);

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
});
