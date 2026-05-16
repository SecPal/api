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
    ]);

    DB::statement('ALTER TABLE address_data_imports RENAME TO address_data_imports_hidden');

    artisan('db:seed', ['--class' => AddressDataSeeder::class])
        ->expectsOutputToContain('Skipped: address data tables are missing')
        ->assertSuccessful();
});
