<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Person;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

// HMAC_SHA256_OUTPUT_BYTES is 32 (SHA-256 output size in bytes)
require_once __DIR__.'/../TestConstants.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

describe('Person Model - Encrypted Casts', function () {
    test('encrypts email_enc field using encrypted cast', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        // Reload from DB
        $person = $person->fresh();

        // Encrypted cast should allow decryption
        $decrypted = $person->email_enc;
        expect($decrypted)->toBe('test@example.com');
    });

    test('encrypts phone_enc field using encrypted cast', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        $person = $person->fresh();
        expect($person->phone_enc)->toBe('+49 123 456789');
    });

    test('encrypts note_enc field when provided', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123456789';
        $person->note_enc = 'Sensitive note';
        $person->save();

        $person = $person->fresh();
        expect($person->note_enc)->toBe('Sensitive note');
    });

    test('note_enc can be null', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123';
        $person->save();

        $person = $person->fresh();
        expect($person->note_enc)->toBeNull();
    });
});

describe('Person Model - Hidden Fields', function () {
    test('hides encrypted fields from JSON', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123456789';
        $person->save();

        $json = $person->toArray();

        expect($json)->not->toHaveKey('email_enc');
        expect($json)->not->toHaveKey('email_idx');
        expect($json)->not->toHaveKey('phone_enc');
        expect($json)->not->toHaveKey('phone_idx');
        expect($json)->not->toHaveKey('note_enc');
    });

    test('exposes non-sensitive fields in JSON', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123';
        $person->save();

        $json = $person->toArray();

        expect($json)->toHaveKey('id');
        expect($json)->toHaveKey('tenant_id');
        expect($json)->toHaveKey('created_at');
        expect($json)->toHaveKey('updated_at');
    });
});

describe('Person Observer - Blind Index Generation', function () {
    test('generates email_idx on creation', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123';
        $person->save();

        $person = $person->fresh();
        expect($person->email_idx)->not->toBeNull();
        // email_idx is stored as base64 string (44 chars for 32 bytes)
        expect(strlen(base64_decode($person->email_idx)))->toBe(HMAC_SHA256_OUTPUT_BYTES);
    });

    test('generates phone_idx on creation', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        $person = $person->fresh();
        expect($person->phone_idx)->not->toBeNull();
        // phone_idx is stored as base64 string (44 chars for 32 bytes)
        expect(strlen(base64_decode($person->phone_idx)))->toBe(HMAC_SHA256_OUTPUT_BYTES);
    });

    test('normalizes email to lowercase for blind index', function (): void {
        $person1 = new Person;
        $person1->tenant_id = $this->tenant->id;
        $person1->email_plain = 'Test@Example.COM';
        $person1->phone_plain = '123';
        $person1->save();

        $person2 = new Person;
        $person2->tenant_id = $this->tenant->id;
        $person2->email_plain = 'test@example.com';
        $person2->phone_plain = '456';
        $person2->save();

        // Same normalized email -> same blind index
        expect($person1->fresh()->email_idx)->toBe($person2->fresh()->email_idx);
    });

    test('normalizes phone to digits-only for blind index', function (): void {
        $person1 = new Person;
        $person1->tenant_id = $this->tenant->id;
        $person1->email_plain = 'test1@example.com';
        $person1->phone_plain = '+49 (123) 456-789';
        $person1->save();

        $person2 = new Person;
        $person2->tenant_id = $this->tenant->id;
        $person2->email_plain = 'test2@example.com';
        $person2->phone_plain = '49123456789';
        $person2->save();

        // Same digits -> same blind index
        expect($person1->fresh()->phone_idx)->toBe($person2->fresh()->phone_idx);
    });

    test('updates blind indexes when email_plain changes', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'old@example.com';
        $person->phone_plain = '123';
        $person->save();

        $oldEmailIdx = $person->fresh()->email_idx;

        // Update email
        $person->email_plain = 'new@example.com';
        $person->save();

        $newEmailIdx = $person->fresh()->email_idx;
        expect($newEmailIdx)->not->toBe($oldEmailIdx);
    });

    test('updates blind indexes when phone_plain changes', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123';
        $person->save();

        $oldPhoneIdx = $person->fresh()->phone_idx;

        // Update phone
        $person->phone_plain = '456';
        $person->save();

        $newPhoneIdx = $person->fresh()->phone_idx;
        expect($newPhoneIdx)->not->toBe($oldPhoneIdx);
    });
});

describe('PersonRepository - Blind Index Search', function () {
    test('findByEmail returns Person with matching email', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '123';
        $person->save();

        $repo = new \App\Repositories\PersonRepository;
        $found = $repo->findByEmail($this->tenant->id, 'test@example.com');

        expect($found)->not->toBeNull();
        expect($found?->id)->toBe($person->id);
    });

    test('findByEmail is case-insensitive', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'Test@Example.COM';
        $person->phone_plain = '123';
        $person->save();

        $repo = new \App\Repositories\PersonRepository;
        $found = $repo->findByEmail($this->tenant->id, 'test@example.com');

        expect($found)->not->toBeNull();
        expect($found?->id)->toBe($person->id);
    });

    test('findByEmail returns null when not found', function (): void {
        $repo = new \App\Repositories\PersonRepository;
        $found = $repo->findByEmail($this->tenant->id, 'nonexistent@example.com');

        expect($found)->toBeNull();
    });

    test('findByPhone returns Person with matching phone', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        $repo = new \App\Repositories\PersonRepository;
        $found = $repo->findByPhone($this->tenant->id, '49123456789');

        expect($found)->not->toBeNull();
        expect($found?->id)->toBe($person->id);
    });

    test('findByPhone ignores formatting', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '49123456789';
        $person->save();

        $repo = new \App\Repositories\PersonRepository;
        $found = $repo->findByPhone($this->tenant->id, '+49 (123) 456-789');

        expect($found)->not->toBeNull();
        expect($found?->id)->toBe($person->id);
    });
});

describe('PersonRepository - Create or Update', function () {
    test('createOrUpdate creates new Person when email not found', function (): void {
        $repo = new \App\Repositories\PersonRepository;

        $person = $repo->createOrUpdate($this->tenant->id, [
            'email_plain' => 'new@example.com',
            'phone_plain' => '123456789',
            'note_enc' => 'Test note',
        ]);

        expect($person)->toBeInstanceOf(Person::class);
        expect($person->id)->not->toBeNull();
        expect($person->email_enc)->toBe('new@example.com');
        expect($person->phone_enc)->toBe('123456789');
        expect($person->note_enc)->toBe('Test note');
    });

    test('createOrUpdate updates existing Person when email found', function (): void {
        $existing = new Person;
        $existing->tenant_id = $this->tenant->id;
        $existing->email_plain = 'existing@example.com';
        $existing->phone_plain = '111';
        $existing->note_enc = 'Old note';
        $existing->save();

        $repo = new \App\Repositories\PersonRepository;

        $updated = $repo->createOrUpdate($this->tenant->id, [
            'email_plain' => 'existing@example.com',
            'phone_plain' => '222',
            'note_enc' => 'Updated note',
        ]);

        expect($updated->id)->toBe($existing->id); // Same ID
        expect($updated->phone_enc)->toBe('222'); // Updated
        expect($updated->note_enc)->toBe('Updated note'); // Updated
    });

    test('createOrUpdate throws exception when email_plain missing', function (): void {
        $repo = new \App\Repositories\PersonRepository;

        expect(fn () => $repo->createOrUpdate($this->tenant->id, [
            'phone_plain' => '123',
        ]))->toThrow(\InvalidArgumentException::class, 'email_plain is required');
    });
});

describe('Person Model - Tenant Isolation', function () {
    test('different tenants have different blind indexes for same email', function (): void {
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        $person1 = new Person;
        $person1->tenant_id = $this->tenant->id;
        $person1->email_plain = 'test@example.com';
        $person1->phone_plain = '123';
        $person1->save();

        $person2 = new Person;
        $person2->tenant_id = $tenant2->id;
        $person2->email_plain = 'test@example.com';
        $person2->phone_plain = '123';
        $person2->save();

        // Different tenants -> different idx_key -> different blind indexes
        expect($person1->fresh()->email_idx)->not->toBe($person2->fresh()->email_idx);
        expect($person1->fresh()->phone_idx)->not->toBe($person2->fresh()->phone_idx);
    });

    test('repository search is tenant-isolated', function (): void {
        $keys2 = TenantKey::generateEnvelopeKeys();
        $tenant2 = TenantKey::create($keys2);

        // Create Person in tenant1
        $person1 = new Person;
        $person1->tenant_id = $this->tenant->id;
        $person1->email_plain = 'test@example.com';
        $person1->phone_plain = '123';
        $person1->save();

        // Create Person with same email in tenant2
        $person2 = new Person;
        $person2->tenant_id = $tenant2->id;
        $person2->email_plain = 'test@example.com';
        $person2->phone_plain = '456';
        $person2->save();

        $repo = new \App\Repositories\PersonRepository;

        // Search in tenant1 -> returns person1
        $found1 = $repo->findByEmail($this->tenant->id, 'test@example.com');
        expect($found1?->id)->toBe($person1->id);

        // Search in tenant2 -> returns person2
        $found2 = $repo->findByEmail($tenant2->id, 'test@example.com');
        expect($found2?->id)->toBe($person2->id);
    });
});
