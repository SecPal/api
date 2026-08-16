<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

function seedActiveAddressFixture(): AddressDataImport
{
    $import = AddressDataImport::query()->create([
        'country_code' => 'DE',
        'source_name' => 'Fixture',
        'source_url' => 'https://example.test/data.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED,
        'row_count' => 2,
        'started_at' => now(),
        'finished_at' => now(),
        'activated_at' => now(),
        'license' => 'ODbL-1.0',
        'attribution' => 'Fixture',
        'source_sha256' => str_repeat('a', 64),
    ]);

    AddressStreet::query()->create([
        'import_id' => $import->id,
        'country_code' => 'DE',
        'name' => 'Grabstraße',
        'postal_code' => '13156',
        'locality' => 'Berlin',
        'regional_key' => null,
        'borough' => null,
        'suburb' => null,
        'name_search' => 'grabstrasse',
        'name_search_ascii' => 'grabstrasse',
        'locality_search' => 'berlin',
        'locality_search_ascii' => 'berlin',
    ]);

    AddressStreet::query()->create([
        'import_id' => $import->id,
        'country_code' => 'DE',
        'name' => 'Other Street',
        'postal_code' => '10115',
        'locality' => 'Berlin',
        'regional_key' => null,
        'borough' => null,
        'suburb' => null,
        'name_search' => 'other street',
        'locality_search' => 'berlin',
        'name_search_ascii' => 'other street',
        'locality_search_ascii' => 'berlin',
    ]);

    return $import;
}

test('address streets requires authentication', function (): void {
    $this->getJson('/v1/addresses/de/streets?name=gr')
        ->assertUnauthorized();
});

test('address streets forbids token without api-access ability', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, []);

    $this->getJson('/v1/addresses/de/streets?name=gr')
        ->assertForbidden();
});

test('address streets returns data for authenticated user', function (): void {
    seedActiveAddressFixture();

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/streets?name=gr&postal_code=13156')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Grabstraße')
        ->assertJsonPath('meta.license', 'ODbL-1.0')
        ->assertJsonStructure([
            'data',
            'meta' => ['source', 'license', 'attribution', 'imported_at', 'version_hash'],
        ]);
});

test('address endpoints return 503 without active import', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/streets?name=gr')
        ->assertStatus(503)
        ->assertJsonPath('code', 'address_data_unavailable');
});

test('address endpoints return 503 when address data tables are missing', function (): void {
    // PostgreSQL DDL is transactional; both renames are rolled back with the test transaction.
    DB::statement('ALTER TABLE address_streets RENAME TO address_streets_hidden');
    DB::statement('ALTER TABLE address_data_imports RENAME TO address_data_imports_hidden');

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/localities?postal_code=101')
        ->assertStatus(503)
        ->assertJsonPath('code', 'address_data_unavailable');
});

test('address streets validation rejects empty filters', function (): void {
    seedActiveAddressFixture();

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/streets')
        ->assertStatus(422);
});

test('localities search returns distinct rows', function (): void {
    seedActiveAddressFixture();

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/localities?postal_code=101&locality=ber')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('address status returns active import metadata', function (): void {
    $import = seedActiveAddressFixture();

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $response = $this->getJson('/v1/addresses/de/status')
        ->assertOk()
        ->assertJsonPath('data.country', 'DE')
        ->assertJsonPath('data.row_count', $import->row_count)
        ->assertJsonPath('meta.version_hash', str_repeat('a', 64));

    expect($response->json('data.row_count'))->toBeInt();
});

test('localities search applies limit to distinct postal codes not street rows', function (): void {
    $import = AddressDataImport::query()->create([
        'country_code' => 'DE',
        'source_name' => 'Fixture',
        'source_url' => 'https://example.test/data.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED,
        'row_count' => 11,
        'started_at' => now(),
        'finished_at' => now(),
        'activated_at' => now(),
        'license' => 'ODbL-1.0',
        'attribution' => 'Fixture',
        'source_sha256' => str_repeat('b', 64),
    ]);

    for ($i = 0; $i < 10; $i++) {
        AddressStreet::query()->create([
            'import_id' => $import->id,
            'country_code' => 'DE',
            'name' => 'Wuppertal Straße '.$i,
            'postal_code' => '42103',
            'locality' => 'Wuppertal',
            'regional_key' => null,
            'borough' => null,
            'suburb' => null,
            'name_search' => 'wuppertal strasse '.$i,
            'locality_search' => 'wuppertal',
            'name_search_ascii' => 'wuppertal strasse '.$i,
            'locality_search_ascii' => 'wuppertal',
        ]);
    }

    AddressStreet::query()->create([
        'import_id' => $import->id,
        'country_code' => 'DE',
        'name' => 'Elberfelder Straße',
        'postal_code' => '42109',
        'locality' => 'Wuppertal',
        'regional_key' => null,
        'borough' => null,
        'suburb' => null,
        'name_search' => 'elberfelder strasse',
        'locality_search' => 'wuppertal',
        'name_search_ascii' => 'elberfelder strasse',
        'locality_search_ascii' => 'wuppertal',
    ]);

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/localities?postal_code=4210')
        ->assertOk()
        ->assertJsonFragment(['postal_code' => '42103'])
        ->assertJsonFragment(['postal_code' => '42109']);
});

test('address streets match ascii fallback input for umlauted street names', function (): void {
    $import = AddressDataImport::query()->create([
        'country_code' => 'DE',
        'source_name' => 'Fixture',
        'source_url' => 'https://example.test/data.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED,
        'row_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
        'activated_at' => now(),
        'license' => 'ODbL-1.0',
        'attribution' => 'Fixture',
        'source_sha256' => str_repeat('c', 64),
    ]);

    AddressStreet::query()->create([
        'import_id' => $import->id,
        'country_code' => 'DE',
        'name' => 'Müllerweg',
        'postal_code' => '01067',
        'locality' => 'Dresden',
        'regional_key' => null,
        'borough' => null,
        'suburb' => null,
        'name_search' => 'muellerweg',
        'name_search_ascii' => 'mullerweg',
        'locality_search' => 'dresden',
        'locality_search_ascii' => 'dresden',
    ]);

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/streets?name=Muller')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Müllerweg');
});

test('localities search matches ascii fallback input for umlauted localities', function (): void {
    $import = AddressDataImport::query()->create([
        'country_code' => 'DE',
        'source_name' => 'Fixture',
        'source_url' => 'https://example.test/data.csv',
        'status' => AddressDataImport::STATUS_SUCCEEDED,
        'row_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
        'activated_at' => now(),
        'license' => 'ODbL-1.0',
        'attribution' => 'Fixture',
        'source_sha256' => str_repeat('d', 64),
    ]);

    AddressStreet::query()->create([
        'import_id' => $import->id,
        'country_code' => 'DE',
        'name' => 'Domplatte',
        'postal_code' => '50667',
        'locality' => 'Köln',
        'regional_key' => null,
        'borough' => null,
        'suburb' => null,
        'name_search' => 'domplatte',
        'name_search_ascii' => 'domplatte',
        'locality_search' => 'koeln',
        'locality_search_ascii' => 'koln',
    ]);

    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/localities?locality=Koln')
        ->assertOk()
        ->assertJsonFragment(['locality' => 'Köln']);
});

test('street search returns 422 and no 500 when name param is an array', function () {
    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/streets?name[]=foo&name[]=bar&postal_code=10115&locality=Berlin')
        ->assertUnprocessable();
});

test('street search returns 422 and no 500 when postal code param is an array', function () {
    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/streets?name=foo&postal_code[]=10115&postal_code[]=10117&locality=Berlin')
        ->assertUnprocessable();
});

test('locality search returns 422 and no 500 when locality param is an array', function () {
    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/localities?locality[]=Berlin&locality[]=Hamburg')
        ->assertUnprocessable();
});

test('locality search returns 422 and no 500 when postal code param is an array', function () {
    /** @var User $user */
    $user = User::factory()->create();
    Sanctum::actingAs($user, [User::API_ACCESS_ABILITY]);

    $this->getJson('/v1/addresses/de/localities?postal_code[]=10115&postal_code[]=10117&locality=Berlin')
        ->assertUnprocessable();
});
