<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Services\AddressData\AddressSuggestionService;
use App\Support\AddressSearchNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('unit', 'services', 'address-data');

beforeEach(function (): void {
    $this->service = new AddressSuggestionService;
    $this->service->forgetActiveImportCache('DE');

    $this->import = AddressDataImport::create([
        'country_code' => 'DE',
        'source_name' => 'Test import',
        'source_url' => 'https://example.com/address-data.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED,
        'activated_at' => now(),
    ]);
});

test('active import ignores stale cached ids for deactivated imports', function (): void {
    expect($this->service->activeImport('DE')?->is($this->import))->toBeTrue();

    $this->import->update(['activated_at' => null]);

    $replacement = AddressDataImport::create([
        'country_code' => 'DE',
        'source_name' => 'Replacement import',
        'source_url' => 'https://example.com/address-data-new.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED,
        'activated_at' => now(),
    ]);

    $active = $this->service->activeImport('DE');

    expect($active)->not->toBeNull()
        ->and($active?->is($replacement))->toBeTrue();
});

test('street suggestions escape wildcard characters in name prefixes', function (): void {
    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Alpha Street',
        'postal_code' => '10115',
        'locality' => 'Berlin',
        'name_search' => AddressSearchNormalizer::normalize('Alpha Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Alpha Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Berlin'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Berlin'),
    ]);

    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Beta Street',
        'postal_code' => '10117',
        'locality' => 'Berlin',
        'name_search' => AddressSearchNormalizer::normalize('Beta Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Beta Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Berlin'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Berlin'),
    ]);

    $results = $this->service->suggestStreets('DE', '%%', null, null, 10);

    expect($results)->toHaveCount(0);
});

test('locality suggestions escape wildcard characters in locality prefixes', function (): void {
    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Alpha Street',
        'postal_code' => '10115',
        'locality' => 'Berlin',
        'name_search' => AddressSearchNormalizer::normalize('Alpha Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Alpha Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Berlin'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Berlin'),
    ]);

    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Gamma Street',
        'postal_code' => '50667',
        'locality' => 'Koln',
        'name_search' => AddressSearchNormalizer::normalize('Gamma Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Gamma Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Koln'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Koln'),
    ]);

    $results = $this->service->suggestLocalities('DE', null, '__', 10);

    expect($results)->toHaveCount(0);
});

test('street suggestions escape wildcard characters in postal code prefixes', function (): void {
    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Alpha Street',
        'postal_code' => '10115',
        'locality' => 'Berlin',
        'name_search' => AddressSearchNormalizer::normalize('Alpha Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Alpha Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Berlin'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Berlin'),
    ]);

    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Beta Street',
        'postal_code' => '20115',
        'locality' => 'Hamburg',
        'name_search' => AddressSearchNormalizer::normalize('Beta Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Beta Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Hamburg'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Hamburg'),
    ]);

    $results = $this->service->suggestStreets('DE', null, '10%', null, 10);

    expect($results)->toHaveCount(0);
});

test('locality suggestions escape wildcard characters in postal code prefixes', function (): void {
    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Alpha Street',
        'postal_code' => '10115',
        'locality' => 'Berlin',
        'name_search' => AddressSearchNormalizer::normalize('Alpha Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Alpha Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Berlin'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Berlin'),
    ]);

    AddressStreet::create([
        'import_id' => $this->import->id,
        'country_code' => 'DE',
        'name' => 'Gamma Street',
        'postal_code' => '10125',
        'locality' => 'Berlin',
        'name_search' => AddressSearchNormalizer::normalize('Gamma Street'),
        'name_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Gamma Street'),
        'locality_search' => AddressSearchNormalizer::normalize('Berlin'),
        'locality_search_ascii' => AddressSearchNormalizer::normalizeAsciiFallback('Berlin'),
    ]);

    $results = $this->service->suggestLocalities('DE', '10_', null, 10);

    expect($results)->toHaveCount(0);
});
