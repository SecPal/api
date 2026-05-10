<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('unit', 'employee', 'bewachv');

beforeEach(function () {
    if (! file_exists(TenantKey::getKekPath())) {
        TenantKey::generateKek();
    }
    $this->employee = Employee::factory()->create();
});

test('it encrypts and decrypts birth name', function () {
    $birthName = 'Schmidt';
    $this->employee->birth_name = $birthName;
    $this->employee->save();

    // Raw encrypted value should NOT equal plaintext
    expect($this->employee->getAttributes()['birth_name_enc'])->not->toEqual($birthName)
        ->and($this->employee->getAttributes()['birth_name_enc'])->not->toBeNull();

    $this->employee->refresh();
    expect($this->employee->birth_name)->toEqual($birthName);
});

test('it encrypts and decrypts structured address street', function () {
    $street = 'Hauptstraße';
    $this->employee->addresses()->delete();

    $address = EmployeeAddress::factory()->current()->create([
        'employee_id' => $this->employee->id,
        'tenant_id' => $this->employee->tenant_id,
        'street' => $street,
        'country' => 'DE',
    ]);

    expect($address->getAttributes()['street_enc'])->not->toEqual($street);
    $address->refresh();
    expect($address->street)->toEqual($street);
});

test('it encrypts and decrypts id document number', function () {
    $docNumber = 'L01X00T471';
    $this->employee->id_document_number = $docNumber;
    $this->employee->save();

    expect($this->employee->getAttributes()['id_document_number_enc'])->not->toEqual($docNumber);
    $this->employee->refresh();
    expect($this->employee->id_document_number)->toEqual($docNumber);
});

test('it encrypts and decrypts work permit number', function () {
    $permitNumber = 'WP-TR-12345';
    $this->employee->work_permit_number = $permitNumber;
    $this->employee->save();

    expect($this->employee->getAttributes()['work_permit_number_enc'])->not->toEqual($permitNumber)
        ->and($this->employee->getAttributes()['work_permit_number_enc'])->not->toBeNull();

    $this->employee->refresh();
    expect($this->employee->work_permit_number)->toEqual($permitNumber);
});

test('it encrypts and decrypts firearms license number', function () {
    $licenseNumber = 'WL-778899';
    $this->employee->firearms_license_number = $licenseNumber;
    $this->employee->save();

    expect($this->employee->getAttributes()['firearms_license_number_enc'])->not->toEqual($licenseNumber)
        ->and($this->employee->getAttributes()['firearms_license_number_enc'])->not->toBeNull();

    $this->employee->refresh();
    expect($this->employee->firearms_license_number)->toEqual($licenseNumber);
});

test('it formats structured address correctly', function () {
    $this->employee->addresses()->delete();

    EmployeeAddress::factory()->current()->create([
        'employee_id' => $this->employee->id,
        'tenant_id' => $this->employee->tenant_id,
        'street' => 'Hauptstraße',
        'house_number' => '42',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'supplement' => 'Hinterhaus',
        'country' => 'DE',
    ]);

    expect($this->employee->fresh(['addresses'])->structured_address)->toEqual('Hauptstraße 42, Hinterhaus, 10115 Berlin, DE');
});

test('it casts nationalities to array', function () {
    $nationalities = ['DE', 'PL'];
    $this->employee->nationalities = $nationalities;
    $this->employee->save();

    $this->employee->refresh();
    expect($this->employee->nationalities)->toBeArray()
        ->and($this->employee->nationalities)->toEqual($nationalities);
});

test('it determines whether a work permit is required from nationalities', function () {
    $this->employee->nationalities = ['DE'];
    $this->employee->save();

    expect($this->employee->requiresWorkPermit())->toBeFalse();

    $this->employee->nationalities = ['TR'];
    $this->employee->save();

    expect($this->employee->requiresWorkPermit())->toBeTrue();
});

test('it determines whether work authorization is valid for non eu employees', function () {
    $this->employee->update([
        'nationalities' => ['TR'],
        'work_permit_type' => 'none',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);

    expect($this->employee->fresh()->hasValidWorkAuthorization())->toBeFalse();

    $this->employee->update([
        'work_permit_type' => 'permanent',
        'work_permit_number' => 'WP-VALID-1',
        'work_permit_issued_by' => 'Auslaenderbehoerde Berlin',
        'work_permit_expiry' => null,
    ]);

    expect($this->employee->fresh()->hasValidWorkAuthorization())->toBeTrue();

    $this->employee->update([
        'work_permit_type' => 'temporary',
        'work_permit_expiry' => now()->subDay()->toDateString(),
    ]);

    expect($this->employee->fresh()->hasValidWorkAuthorization())->toBeFalse();

    $this->employee->update([
        'nationalities' => ['DE'],
        'work_permit_type' => 'none',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);

    expect($this->employee->fresh()->hasValidWorkAuthorization())->toBeTrue();
});

test('it returns expiring compliance documents within 30 days', function () {
    $this->employee->update([
        'nationalities' => ['TR'],
        'work_permit_type' => 'temporary',
        'work_permit_number' => 'WP-EXP-1',
        'work_permit_issued_by' => 'Auslaenderbehoerde Berlin',
        'work_permit_expiry' => now()->addDays(5)->toDateString(),
        'residence_permit_expiry' => now()->subDay()->toDateString(),
        'id_document_expiry' => now()->addDays(45)->toDateString(),
    ]);

    $documents = $this->employee->fresh()->expiring_documents;

    expect($documents)->toBeInstanceOf(Illuminate\Support\Collection::class)
        ->and($documents->pluck('type')->all())->toContain('work_permit', 'residence_permit')
        ->and($documents->pluck('type')->all())->not->toContain('id_document');
});

test('it aggregates expiring employee certifications and additional certifications', function () {
    $this->employee->update([
        'firearms_license_number' => 'WL-12345',
        'firearms_license_expiry' => now()->addDays(4)->toDateString(),
        'firearms_license_issued_by' => 'Polizeipraesidium Berlin',
        'first_aid_cert_number' => 'FA-987',
        'first_aid_cert_date' => now()->subYear()->toDateString(),
        'first_aid_cert_expiry' => now()->addDays(15)->toDateString(),
        'fire_safety_cert_date' => now()->subYear()->toDateString(),
        'fire_safety_cert_expiry' => now()->addDays(40)->toDateString(),
        'evacuation_cert_date' => now()->subYear()->toDateString(),
        'evacuation_cert_expiry' => now()->subDay()->toDateString(),
        'additional_certifications' => [
            [
                'name' => 'Site Access Badge',
                'number' => 'BADGE-7',
                'issued_date' => now()->subMonth()->toDateString(),
                'expiry_date' => now()->addDays(2)->toDateString(),
                'issuer' => 'Customer Security',
            ],
            [
                'name' => 'Long-term Clearance',
                'issued_date' => now()->subMonth()->toDateString(),
                'expiry_date' => now()->addDays(60)->toDateString(),
                'issuer' => 'Customer Security',
            ],
        ],
    ]);

    $documents = $this->employee->fresh()->expiring_documents;

    expect($documents->pluck('type')->all())
        ->toContain('firearms_license', 'first_aid_certificate', 'evacuation_certificate', 'additional_certification')
        ->not->toContain('fire_safety_certificate');
});

test('it persists historical addresses as separate rows', function () {
    $this->employee->addresses()->delete();

    EmployeeAddress::factory()->create([
        'employee_id' => $this->employee->id,
        'tenant_id' => $this->employee->tenant_id,
        'street' => 'Alte Straße',
        'city' => 'Hamburg',
        'postal_code' => '20095',
        'country' => 'DE',
        'resided_from' => '2019-01-01',
        'resided_until' => '2021-12-31',
    ]);

    $employee = $this->employee->fresh(['addresses']);

    expect($employee->addresses)->toHaveCount(1)
        ->and($employee->addresses->first()->street)->toBe('Alte Straße');
});

test('it casts emergency contacts to array', function () {
    $emergencyContacts = [
        [
            'name' => 'Max Mustermann',
            'relationship' => 'Partner',
            'phone' => '+49 151 1234567',
            'email' => 'max.mustermann@secpal.dev',
            'notes' => 'Primärer Notfallkontakt',
        ],
    ];

    $this->employee->emergency_contacts = $emergencyContacts;
    $this->employee->save();

    $this->employee->refresh();
    expect($this->employee->emergency_contacts)->toBeArray()
        ->and($this->employee->emergency_contacts)->toEqual($emergencyContacts);
});

test('it stores bwr id as string with leading zeros', function () {
    $bwrId = '0123456';
    $this->employee->bwr_id = $bwrId;
    $this->employee->save();

    $this->employee->refresh();
    expect($this->employee->bwr_id)->toBeString()
        ->and($this->employee->bwr_id)->toEqual($bwrId)
        ->and(strlen($this->employee->bwr_id))->toEqual(7);
});

test('it casts bwr registered at to datetime', function () {
    $date = now();
    $this->employee->bwr_registered_at = $date;
    $this->employee->save();

    $this->employee->refresh();
    expect($this->employee->bwr_registered_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

test('it handles null values for optional encrypted fields', function () {
    $this->employee->update(['birth_name' => null, 'id_document_number' => null]);
    expect($this->employee->birth_name)->toBeNull()
        ->and($this->employee->id_document_number)->toBeNull();
});

test('it hides encrypted fields from array', function () {
    $this->employee->update(['birth_name' => 'TestName', 'id_document_number' => 'TEST123']);
    $array = $this->employee->toArray();

    // Encrypted *_enc fields should be hidden (in $hidden array)
    expect($array)->not->toHaveKey('birth_name_enc')
        ->and($array)->not->toHaveKey('id_document_number_enc')
        ->and($array)->not->toHaveKey('first_name_enc')
        ->and($array)->not->toHaveKey('last_name_enc')
        ->and($array)->not->toHaveKey('date_of_birth_enc');
});
