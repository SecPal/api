<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function loadDropLegacyEmployeeAddressColumnsMigration(): object
{
    return require database_path('migrations/2026_05_10_120001_drop_legacy_employee_address_columns_from_employees_table.php');
}

function encryptLegacyAddressValue(int $tenantId, ?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $tenant = TenantKey::query()->findOrFail($tenantId);
    $encrypted = $tenant->encrypt($value);

    return json_encode([
        'ciphertext' => base64_encode($encrypted['ciphertext']),
        'nonce' => base64_encode($encrypted['nonce']),
    ], JSON_THROW_ON_ERROR);
}

function decryptLegacyAddressValue(int $tenantId, ?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    /** @var array{ciphertext: string, nonce: string} $decoded */
    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    $tenant = TenantKey::query()->findOrFail($tenantId);

    return $tenant->decrypt(
        base64_decode($decoded['ciphertext'], true) ?: throw new RuntimeException('Invalid ciphertext.'),
        base64_decode($decoded['nonce'], true) ?: throw new RuntimeException('Invalid nonce.')
    );
}

test('employee_addresses table exists with expected columns and partial unique index', function (): void {
    expect(Schema::hasTable('employee_addresses'))->toBeTrue();
    expect(Schema::hasColumns('employee_addresses', [
        'id',
        'employee_id',
        'tenant_id',
        'street_enc',
        'house_number_enc',
        'postal_code_enc',
        'city_enc',
        'supplement_enc',
        'country',
        'resided_from',
        'resided_until',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $indexNames = array_map(
        static fn (object $row): string => (string) $row->indexname,
        DB::select("select indexname from pg_indexes where schemaname = current_schema() and tablename = 'employee_addresses'")
    );
    expect($indexNames)->toContain('employee_addresses_one_current_per_employee');
});

test('employees table no longer has legacy flat address columns or address_history', function (): void {
    expect(Schema::hasColumn('employees', 'address_street_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_house_number_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_postal_code_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_city_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_supplement_enc'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_country'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_state'))->toBeFalse();
    expect(Schema::hasColumn('employees', 'address_history'))->toBeFalse();
});

test('the breaking legal entity migration prevents rollback into an unsupported schema', function (): void {
    expect(Schema::hasColumn('employees', 'birth_state'))->toBeFalse();
    expect(Schema::hasColumn('employee_addresses', 'state'))->toBeFalse();

    expect(fn () => $this->artisan('migrate:rollback', ['--step' => 1]))
        ->toThrow(RuntimeException::class, 'intentionally irreversible');
});

test('dropping legacy employee address columns backfills current and historical rows first', function (): void {
    $employee = Employee::factory()->create();
    $employee->addresses()->delete();

    $migration = loadDropLegacyEmployeeAddressColumnsMigration();
    $migration->down();

    $currentStreet = encryptLegacyAddressValue($employee->tenant_id, 'Musterstrasse');
    $currentHouseNumber = encryptLegacyAddressValue($employee->tenant_id, '42');
    $currentPostalCode = encryptLegacyAddressValue($employee->tenant_id, '10115');
    $currentCity = encryptLegacyAddressValue($employee->tenant_id, 'Berlin');
    $currentSupplement = encryptLegacyAddressValue($employee->tenant_id, '3. OG');

    DB::table('employees')
        ->where('id', $employee->id)
        ->update([
            'address_street_enc' => $currentStreet,
            'address_house_number_enc' => $currentHouseNumber,
            'address_postal_code_enc' => $currentPostalCode,
            'address_city_enc' => $currentCity,
            'address_supplement_enc' => $currentSupplement,
            'address_country' => 'DE',
            'address_state' => 'BE',
            'address_history' => json_encode([
                [
                    'street' => 'Altstrasse',
                    'house_number' => '7',
                    'postal_code' => '50667',
                    'city' => 'Koeln',
                    'supplement' => 'Hinterhaus',
                    'country' => 'DE',
                    'state' => 'NW',
                    'resided_from' => '2020-01-01',
                    'resided_until' => '2023-12-31',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

    $migration->up();

    $rows = DB::table('employee_addresses')
        ->where('employee_id', $employee->id)
        ->orderByRaw('resided_until is null desc')
        ->orderBy('resided_from')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and(Schema::hasColumn('employees', 'address_street_enc'))->toBeFalse();

    $current = $rows->firstWhere('resided_until', null);
    $historical = $rows->firstWhere('resided_until', '2023-12-31');

    expect($current)->not->toBeNull()
        ->and($historical)->not->toBeNull()
        ->and($current->street_enc)->toBe($currentStreet)
        ->and($current->house_number_enc)->toBe($currentHouseNumber)
        ->and($current->postal_code_enc)->toBe($currentPostalCode)
        ->and($current->city_enc)->toBe($currentCity)
        ->and($current->supplement_enc)->toBe($currentSupplement)
        ->and($current->country)->toBe('DE')
        ->and($historical->country)->toBe('DE')
        ->and($historical->resided_from)->toBe('2020-01-01')
        ->and($historical->resided_until)->toBe('2023-12-31')
        ->and(decryptLegacyAddressValue($employee->tenant_id, $historical->street_enc))->toBe('Altstrasse')
        ->and(decryptLegacyAddressValue($employee->tenant_id, $historical->city_enc))->toBe('Koeln');
});

test('rolling back the legacy employee address column drop restores flat columns from employee_addresses', function (): void {
    $employee = Employee::factory()->create();
    $employee->addresses()->delete();

    $migration = loadDropLegacyEmployeeAddressColumnsMigration();
    $migration->down();

    $currentStreet = encryptLegacyAddressValue($employee->tenant_id, 'Musterstrasse');
    $currentHouseNumber = encryptLegacyAddressValue($employee->tenant_id, '42');
    $currentPostalCode = encryptLegacyAddressValue($employee->tenant_id, '10115');
    $currentCity = encryptLegacyAddressValue($employee->tenant_id, 'Berlin');
    $currentSupplement = encryptLegacyAddressValue($employee->tenant_id, '3. OG');

    DB::table('employees')
        ->where('id', $employee->id)
        ->update([
            'address_street_enc' => $currentStreet,
            'address_house_number_enc' => $currentHouseNumber,
            'address_postal_code_enc' => $currentPostalCode,
            'address_city_enc' => $currentCity,
            'address_supplement_enc' => $currentSupplement,
            'address_country' => 'DE',
            'address_state' => 'BE',
            'address_history' => json_encode([
                [
                    'street' => 'Altstrasse',
                    'house_number' => '7',
                    'postal_code' => '50667',
                    'city' => 'Koeln',
                    'supplement' => 'Hinterhaus',
                    'country' => 'DE',
                    'state' => 'NW',
                    'resided_from' => '2020-01-01',
                    'resided_until' => '2023-12-31',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

    $migration->up();
    $migration->down();

    $employeeRow = DB::table('employees')
        ->where('id', $employee->id)
        ->first();

    expect(Schema::hasColumn('employees', 'address_street_enc'))->toBeTrue()
        ->and($employeeRow)->not->toBeNull()
        ->and($employeeRow->address_street_enc)->toBe($currentStreet)
        ->and($employeeRow->address_house_number_enc)->toBe($currentHouseNumber)
        ->and($employeeRow->address_postal_code_enc)->toBe($currentPostalCode)
        ->and($employeeRow->address_city_enc)->toBe($currentCity)
        ->and($employeeRow->address_supplement_enc)->toBe($currentSupplement)
        ->and($employeeRow->address_country)->toBe('DE')
        ->and($employeeRow->address_state)->toBeNull()
        ->and(json_decode((string) $employeeRow->address_history, true, 512, JSON_THROW_ON_ERROR))->toBe([
            [
                'street' => 'Altstrasse',
                'house_number' => '7',
                'postal_code' => '50667',
                'city' => 'Koeln',
                'supplement' => 'Hinterhaus',
                'country' => 'DE',
                'resided_from' => '2020-01-01',
                'resided_until' => '2023-12-31',
            ],
        ]);
});

test('dropping legacy employee address columns merges remaining legacy history when current relation rows already exist', function (): void {
    $employee = Employee::factory()->create();
    $employee->addresses()->delete();

    $existingCurrentStreet = encryptLegacyAddressValue($employee->tenant_id, 'Neue Strasse');
    $existingCurrentHouseNumber = encryptLegacyAddressValue($employee->tenant_id, '5');
    $existingCurrentPostalCode = encryptLegacyAddressValue($employee->tenant_id, '80331');
    $existingCurrentCity = encryptLegacyAddressValue($employee->tenant_id, 'Muenchen');

    DB::table('employee_addresses')->insert([
        'id' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'tenant_id' => $employee->tenant_id,
        'street_enc' => $existingCurrentStreet,
        'house_number_enc' => $existingCurrentHouseNumber,
        'postal_code_enc' => $existingCurrentPostalCode,
        'city_enc' => $existingCurrentCity,
        'supplement_enc' => null,
        'country' => 'DE',
        'resided_from' => null,
        'resided_until' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadDropLegacyEmployeeAddressColumnsMigration();
    $migration->down();

    DB::table('employees')
        ->where('id', $employee->id)
        ->update([
            'address_street_enc' => $existingCurrentStreet,
            'address_house_number_enc' => $existingCurrentHouseNumber,
            'address_postal_code_enc' => $existingCurrentPostalCode,
            'address_city_enc' => $existingCurrentCity,
            'address_supplement_enc' => null,
            'address_country' => 'DE',
            'address_state' => 'BY',
            'address_history' => json_encode([
                [
                    'street' => 'Altstadtweg',
                    'house_number' => '11',
                    'postal_code' => '50667',
                    'city' => 'Koeln',
                    'supplement' => null,
                    'country' => 'DE',
                    'resided_from' => '2018-01-01',
                    'resided_until' => '2020-12-31',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

    $migration->up();

    $rows = DB::table('employee_addresses')
        ->where('employee_id', $employee->id)
        ->orderByRaw('resided_until is null desc')
        ->orderBy('resided_from')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->where('resided_until', null))->toHaveCount(1)
        ->and($rows->where('resided_until', '2020-12-31'))->toHaveCount(1)
        ->and(decryptLegacyAddressValue($employee->tenant_id, (string) $rows->firstWhere('resided_until', '2020-12-31')->street_enc))->toBe('Altstadtweg');
});

test('dropping legacy employee address columns reuses tenant key lookups during legacy backfill', function (): void {
    $employee = Employee::factory()->create();
    $employee->addresses()->delete();

    $existingHistoricalStreet = encryptLegacyAddressValue($employee->tenant_id, 'Altstadtweg');
    $existingHistoricalHouseNumber = encryptLegacyAddressValue($employee->tenant_id, '11');
    $existingHistoricalPostalCode = encryptLegacyAddressValue($employee->tenant_id, '50667');
    $existingHistoricalCity = encryptLegacyAddressValue($employee->tenant_id, 'Koeln');

    DB::table('employee_addresses')->insert([
        'id' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'tenant_id' => $employee->tenant_id,
        'street_enc' => $existingHistoricalStreet,
        'house_number_enc' => $existingHistoricalHouseNumber,
        'postal_code_enc' => $existingHistoricalPostalCode,
        'city_enc' => $existingHistoricalCity,
        'supplement_enc' => null,
        'country' => 'DE',
        'resided_from' => '2018-01-01',
        'resided_until' => '2020-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadDropLegacyEmployeeAddressColumnsMigration();
    $migration->down();

    DB::table('employees')
        ->where('id', $employee->id)
        ->update([
            'address_street_enc' => null,
            'address_house_number_enc' => null,
            'address_postal_code_enc' => null,
            'address_city_enc' => null,
            'address_supplement_enc' => null,
            'address_country' => null,
            'address_state' => null,
            'address_history' => json_encode([
                [
                    'street' => 'Altstadtweg',
                    'house_number' => '11',
                    'postal_code' => '50667',
                    'city' => 'Koeln',
                    'supplement' => null,
                    'country' => 'DE',
                    'resided_from' => '2018-01-01',
                    'resided_until' => '2020-12-31',
                ],
                [
                    'street' => 'Neustadtweg',
                    'house_number' => '12',
                    'postal_code' => '50668',
                    'city' => 'Koeln',
                    'supplement' => 'Hinterhaus',
                    'country' => 'DE',
                    'resided_from' => '2021-01-01',
                    'resided_until' => '2022-12-31',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

    $migration->up();

    $tenantKeysCache = (new ReflectionClass($migration))->getProperty('tenantKeysById');
    $tenantKeysCache->setAccessible(true);

    expect($tenantKeysCache->getValue($migration))->toHaveCount(1)
        ->and($tenantKeysCache->getValue($migration))->toHaveKey($employee->tenant_id);
});
