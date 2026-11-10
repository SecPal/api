<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use Database\Seeders\AddressDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('address data seeder imports fixture data when setup import is enabled', function (): void {
    config([
        'address_data.import_on_setup' => true,
        'address_data.setup_source_path' => base_path('tests/fixtures/address_data/sample_streets.csv'),
        'address_data.expected_sha256' => hash_file('sha256', base_path('tests/fixtures/address_data/sample_streets.csv')),
    ]);

    artisan('db:seed', ['--class' => AddressDataSeeder::class])->assertSuccessful();

    $active = AddressDataImport::query()->whereNotNull('activated_at')->first();

    expect($active)->not->toBeNull()
        ->and($active->row_count)->toBe(3)
        ->and(AddressStreet::query()->where('import_id', $active->id)->count())->toBe(3);
});

test('address data seeder respects disabled setup import flag', function (): void {
    config([
        'address_data.import_on_setup' => false,
        'address_data.setup_source_path' => '/path/that/should/not/be/read.csv',
    ]);

    artisan('db:seed', ['--class' => AddressDataSeeder::class])->assertSuccessful();

    expect(AddressDataImport::query()->count())->toBe(0)
        ->and(AddressStreet::query()->count())->toBe(0);
});

test('address data seeder skips gracefully when address data tables are missing', function (): void {
    config([
        'address_data.import_on_setup' => true,
        'address_data.setup_source_path' => base_path('tests/fixtures/address_data/sample_streets.csv'),
        'address_data.expected_sha256' => hash_file('sha256', base_path('tests/fixtures/address_data/sample_streets.csv')),
    ]);

    DB::statement('ALTER TABLE address_data_imports RENAME TO address_data_imports_hidden');

    artisan('db:seed', ['--class' => AddressDataSeeder::class])
        ->expectsOutputToContain('Skipped: address data tables are missing')
        ->assertSuccessful();
});

test('address data seeder upgrades an existing dataset instead of only filling an empty database', function (): void {
    config([
        'address_data.import_on_setup' => true,
        'address_data.setup_source_path' => base_path('tests/fixtures/address_data/sample_streets.csv'),
        'address_data.expected_sha256' => hash_file('sha256', base_path('tests/fixtures/address_data/sample_streets.csv')),
    ]);
    $previous = AddressDataImport::query()->create([
        'country_code' => 'DE', 'source_name' => 'Old release', 'source_url' => 'file://old.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED, 'started_at' => now(),
        'activated_at' => now(), 'source_sha256' => str_repeat('b', 64),
    ]);

    artisan('db:seed', ['--class' => AddressDataSeeder::class])->assertSuccessful();

    expect(AddressDataImport::query()->whereNotNull('activated_at')->value('source_sha256'))
        ->toBe(config('address_data.expected_sha256'))
        ->and($previous->fresh()?->activated_at)->toBeNull();
});
