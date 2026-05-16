<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAddressImport(
    string $countryCode,
    string $status,
    ?string $activatedAt = null,
    ?string $sourceSha256 = null,
): AddressDataImport {
    return AddressDataImport::query()->create([
        'country_code' => $countryCode,
        'source_name' => 'Fixture',
        'source_url' => 'https://example.test/data.csv',
        'status' => $status,
        'row_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
        'activated_at' => $activatedAt,
        'license' => 'ODbL-1.0',
        'attribution' => 'Fixture',
        'source_sha256' => $sourceSha256,
    ]);
}

function createAddressStreet(AddressDataImport $import, string $postalCode, string $name = 'Fixture Street'): void
{
    AddressStreet::query()->create([
        'import_id' => $import->id,
        'country_code' => $import->country_code,
        'name' => $name,
        'postal_code' => $postalCode,
        'locality' => 'Fixture City',
        'regional_key' => null,
        'borough' => null,
        'suburb' => null,
        'name_search' => strtolower($name),
        'name_search_ascii' => strtolower($name),
        'locality_search' => 'fixture city',
        'locality_search_ascii' => 'fixture city',
    ]);
}

test('addresses:import exits with failure and error output when import fails', function (): void {
    $this->artisan('addresses:import', ['--source' => '/nonexistent/csv/path.csv'])
        ->expectsOutputToContain('Address data source file is not readable')
        ->assertFailed();
});

test('addresses:import imports fixture and activates dataset', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $this->artisan('addresses:import', ['--source' => $fixture])
        ->assertSuccessful();

    $active = AddressDataImport::query()->whereNotNull('activated_at')->first();
    expect($active)->not->toBeNull();
    expect($active->row_count)->toBe(3);
    expect(AddressStreet::query()->where('import_id', $active->id)->count())->toBe(3);

    $grab = AddressStreet::query()->where('name_search', 'like', 'grabstrasse%')->first();
    expect($grab)->not->toBeNull();
    expect($grab->postal_code)->toBe('13156');
});

test('addresses:import skips when checksum unchanged', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $this->artisan('addresses:import', ['--source' => $fixture])->assertSuccessful();

    $firstCount = AddressDataImport::query()->count();

    $this->artisan('addresses:import', ['--source' => $fixture])->assertSuccessful();

    expect(AddressDataImport::query()->count())->toBe($firstCount);
});

test('addresses:import setup-only skips when import_on_setup disabled', function (): void {
    config(['address_data.import_on_setup' => false]);

    $this->artisan('addresses:import', ['--setup-only' => true])->assertSuccessful();
});

test('addresses:check reports the active import metadata', function (): void {
    $activeImport = createAddressImport(
        countryCode: 'DE',
        status: AddressDataImport::STATUS_SUCCEEDED,
        activatedAt: now()->toIso8601String(),
        sourceSha256: str_repeat('d', 64),
    );

    $this->artisan('addresses:check')
        ->expectsOutputToContain('Country: DE')
        ->expectsOutputToContain('Source: Fixture')
        ->expectsOutputToContain('Rows: 1')
        ->expectsOutputToContain('License: ODbL-1.0')
        ->expectsOutputToContain('SHA-256: '.str_repeat('d', 64))
        ->assertSuccessful();

    expect($activeImport->fresh()?->id)->toBe($activeImport->id);
});

test('addresses:import keeps street rows from other countries when pruning old imports', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $atImport = createAddressImport(
        countryCode: 'AT',
        status: AddressDataImport::STATUS_SUCCEEDED,
        activatedAt: now()->toIso8601String(),
        sourceSha256: str_repeat('a', 64),
    );
    createAddressStreet($atImport, '1010', 'Wollzeile');

    config(['address_data.country' => 'DE']);

    $this->artisan('addresses:import', ['--source' => $fixture])->assertSuccessful();

    expect(AddressStreet::query()->where('import_id', $atImport->id)->count())->toBe(1);
});

test('addresses:import keep-imports preserves the prior successful dataset when failed attempts exist', function (): void {
    $fixture = tempnam(sys_get_temp_dir(), 'address-data-');
    expect($fixture)->not->toBeFalse();

    file_put_contents($fixture, implode("\n", [
        'Name,PostalCode,Locality,RegionalKey,Borough,Suburb',
        'Neue Straße,99999,Neuhausen,,,',
    ]));

    $previousSuccessfulImport = createAddressImport(
        countryCode: 'DE',
        status: AddressDataImport::STATUS_SUCCEEDED,
        activatedAt: now()->subDay()->toIso8601String(),
        sourceSha256: str_repeat('b', 64),
    );
    createAddressStreet($previousSuccessfulImport, '11111', 'Altstraße');

    createAddressImport(
        countryCode: 'DE',
        status: AddressDataImport::STATUS_FAILED,
        activatedAt: null,
        sourceSha256: str_repeat('c', 64),
    );

    try {
        $this->artisan('addresses:import', [
            '--source' => $fixture,
            '--keep-imports' => 1,
        ])->assertSuccessful();
    } finally {
        @unlink($fixture);
    }

    expect(AddressDataImport::query()->whereKey($previousSuccessfulImport->id)->exists())->toBeTrue();
    expect(AddressStreet::query()->where('import_id', $previousSuccessfulImport->id)->count())->toBe(1);
});

test('addresses:import pruning does not delete concurrently running imports', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $runningImport = AddressDataImport::query()->create([
        'country_code' => 'DE',
        'source_name' => 'Fixture',
        'source_url' => 'https://example.test/data.csv',
        'status' => AddressDataImport::STATUS_RUNNING,
        'row_count' => 0,
        'started_at' => now(),
        'finished_at' => null,
        'activated_at' => null,
        'license' => 'ODbL-1.0',
        'attribution' => 'Fixture',
        'source_sha256' => null,
    ]);
    createAddressStreet($runningImport, '11111', 'Parallelstrasse');

    $this->artisan('addresses:import', ['--source' => $fixture])->assertSuccessful();

    expect(AddressDataImport::query()->whereKey($runningImport->id)->exists())->toBeTrue();
    expect(AddressStreet::query()->where('import_id', $runningImport->id)->count())->toBe(1);
});
