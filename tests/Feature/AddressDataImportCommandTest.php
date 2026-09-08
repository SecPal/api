<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function addressFixtureSha256(): string
{
    $digest = hash_file('sha256', base_path('tests/fixtures/address_data/sample_streets.csv'));

    if (! is_string($digest)) {
        throw new RuntimeException('Could not hash the address-data test fixture.');
    }

    return $digest;
}

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
    $this->withoutMockingConsoleOutput();

    $exitCode = $this->artisan('addresses:import', [
        '--source' => '/nonexistent/csv/path.csv',
        '--expected-sha256' => str_repeat('a', 64),
    ]);
    $output = $this->app->make(Kernel::class)->output();

    expect($exitCode)->toBe(1);
    expect($output)
        ->toContain('ERROR')
        ->toContain('Address data source file is not readable');
});

test('addresses:import imports fixture and activates dataset', function (): void {
    $fixture = base_path('tests/fixtures/address_data/sample_streets.csv');

    $this->artisan('addresses:import', [
        '--source' => $fixture,
        '--expected-sha256' => addressFixtureSha256(),
    ])
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

    $arguments = ['--source' => $fixture, '--expected-sha256' => addressFixtureSha256()];

    $this->artisan('addresses:import', $arguments)->assertSuccessful();

    $firstCount = AddressDataImport::query()->count();

    $this->artisan('addresses:import', $arguments)->assertSuccessful();

    expect(AddressDataImport::query()->count())->toBe($firstCount);
});

test('addresses:import setup-only skips when import_on_setup disabled', function (): void {
    config(['address_data.import_on_setup' => false]);

    $this->artisan('addresses:import', ['--setup-only' => true])->assertSuccessful();
});

test('addresses:import setup-only uses configured setup source path', function (): void {
    config([
        'address_data.import_on_setup' => true,
        'address_data.setup_source_path' => base_path('tests/fixtures/address_data/sample_streets.csv'),
        'address_data.expected_sha256' => addressFixtureSha256(),
    ]);

    $this->artisan('addresses:import', ['--setup-only' => true])->assertSuccessful();

    $active = AddressDataImport::query()->whereNotNull('activated_at')->first();
    expect($active)->not->toBeNull();
    expect($active->row_count)->toBe(3);
    expect(AddressStreet::query()->where('import_id', $active->id)->count())->toBe(3);
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

test('addresses:check reports no active import when address data tables are missing', function (): void {
    DB::statement('ALTER TABLE address_data_imports RENAME TO address_data_imports_hidden');

    $this->artisan('addresses:check')
        ->expectsOutputToContain('No activated address import is available.')
        ->assertSuccessful();
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

    $this->artisan('addresses:import', [
        '--source' => $fixture,
        '--expected-sha256' => addressFixtureSha256(),
    ])->assertSuccessful();

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
            '--expected-sha256' => hash_file('sha256', $fixture),
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

    $this->artisan('addresses:import', [
        '--source' => $fixture,
        '--expected-sha256' => addressFixtureSha256(),
    ])->assertSuccessful();

    expect(AddressDataImport::query()->whereKey($runningImport->id)->exists())->toBeTrue();
    expect(AddressStreet::query()->where('import_id', $runningImport->id)->count())->toBe(1);
});

test('local source requires an expected sha256', function (): void {
    $this->artisan('addresses:import', [
        '--source' => base_path('tests/fixtures/address_data/sample_streets.csv'),
    ])->expectsOutputToContain('exactly 64 lowercase hexadecimal characters')
        ->assertFailed();

    expect(AddressDataImport::query()->count())->toBe(0);
});

test('local source rejects a wrong digest before creating a candidate import', function (): void {
    $this->artisan('addresses:import', [
        '--source' => base_path('tests/fixtures/address_data/sample_streets.csv'),
        '--expected-sha256' => str_repeat('0', 64),
    ])->expectsOutputToContain('does not match the expected SHA-256')
        ->assertFailed();

    expect(AddressDataImport::query()->count())->toBe(0)
        ->and(AddressStreet::query()->count())->toBe(0);
});

test('moving remote source is rejected without any network request', function (): void {
    Http::fake();
    config([
        'address_data.source_url' => 'https://github.com/openpotato/openplzapi.data/raw/refs/heads/main/src/de/osm/streets.updated.csv',
        'address_data.expected_sha256' => str_repeat('a', 64),
    ]);

    $this->artisan('addresses:import')
        ->expectsOutputToContain('immutable commit-pinned GitHub raw URL')
        ->assertFailed();

    Http::assertNothingSent();
});

test('remote source requires an expected sha256 without making a network request', function (): void {
    Http::fake();
    config([
        'address_data.source_url' => 'https://raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv',
        'address_data.expected_sha256' => null,
    ]);

    $this->artisan('addresses:import')
        ->expectsOutputToContain('exactly 64 lowercase hexadecimal characters')
        ->assertFailed();

    Http::assertNothingSent();
});

test('remote source rejects a wrong digest and preserves the active dataset', function (): void {
    $fixture = file_get_contents(base_path('tests/fixtures/address_data/sample_streets.csv'));
    expect($fixture)->not->toBeFalse();

    $url = 'https://raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv';
    Http::fake([$url => Http::response($fixture)]);
    config([
        'address_data.source_url' => $url,
        'address_data.expected_sha256' => str_repeat('0', 64),
    ]);

    $active = createAddressImport(
        countryCode: 'DE',
        status: AddressDataImport::STATUS_SUCCEEDED,
        activatedAt: now()->toIso8601String(),
        sourceSha256: str_repeat('b', 64),
    );
    createAddressStreet($active, '11111', 'Authoritative Street');

    $this->artisan('addresses:import')
        ->expectsOutputToContain('does not match the expected SHA-256')
        ->assertFailed();

    expect($active->fresh()?->activated_at)->not->toBeNull()
        ->and(AddressDataImport::query()->count())->toBe(1)
        ->and(AddressStreet::query()->where('import_id', $active->id)->count())->toBe(1);
});

test('csv validation failure preserves the active dataset', function (): void {
    $invalidCsv = tempnam(sys_get_temp_dir(), 'address-data-invalid-');
    expect($invalidCsv)->not->toBeFalse();
    file_put_contents($invalidCsv, "Wrong,Header\nvalue,value\n");

    $active = createAddressImport(
        countryCode: 'DE',
        status: AddressDataImport::STATUS_SUCCEEDED,
        activatedAt: now()->toIso8601String(),
        sourceSha256: str_repeat('b', 64),
    );
    createAddressStreet($active, '11111', 'Authoritative Street');

    try {
        $this->artisan('addresses:import', [
            '--source' => $invalidCsv,
            '--expected-sha256' => hash_file('sha256', $invalidCsv),
        ])->assertFailed();
    } finally {
        @unlink($invalidCsv);
    }

    expect($active->fresh()?->activated_at)->not->toBeNull()
        ->and(AddressStreet::query()->where('import_id', $active->id)->count())->toBe(1)
        ->and(AddressDataImport::query()->where('status', AddressDataImport::STATUS_FAILED)->count())->toBe(1);
});

test('remote source with a matching digest follows the normal import path', function (): void {
    $fixture = file_get_contents(base_path('tests/fixtures/address_data/sample_streets.csv'));
    expect($fixture)->not->toBeFalse();

    $url = 'https://raw.githubusercontent.com/openpotato/openplzapi.data/'.str_repeat('a', 40).'/src/de/osm/streets.updated.csv';
    Http::fake([$url => Http::response($fixture)]);
    config([
        'address_data.source_url' => $url,
        'address_data.expected_sha256' => addressFixtureSha256(),
    ]);

    $this->artisan('addresses:import')->assertSuccessful();

    expect(AddressDataImport::query()->whereNotNull('activated_at')->value('source_sha256'))
        ->toBe(addressFixtureSha256())
        ->and(AddressStreet::query()->count())->toBe(3);
});

test('force and dry-run cannot bypass source digest admission', function (array $arguments): void {
    $arguments += [
        '--source' => base_path('tests/fixtures/address_data/sample_streets.csv'),
        '--expected-sha256' => str_repeat('0', 64),
    ];

    $this->artisan('addresses:import', $arguments)
        ->expectsOutputToContain('does not match the expected SHA-256')
        ->assertFailed();

    expect(AddressDataImport::query()->count())->toBe(0);
})->with([
    'force' => [['--force' => true]],
    'dry run' => [['--dry-run' => true]],
]);

test('setup import cannot fall back to a moving unauthenticated remote source', function (): void {
    Http::fake();
    config([
        'address_data.import_on_setup' => true,
        'address_data.setup_source_path' => null,
        'address_data.source_url' => 'https://github.com/openpotato/openplzapi.data/raw/refs/heads/main/src/de/osm/streets.updated.csv',
        'address_data.expected_sha256' => null,
    ]);

    $this->artisan('addresses:import', ['--setup-only' => true])->assertFailed();

    Http::assertNothingSent();
});
