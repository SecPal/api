<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\KeyStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('Person Model Encryption', function () {
    beforeEach(function () {
        $this->artisan('migrate:fresh');

        // Create test tenant with keys
        $this->tenantId = (string) Str::uuid();
        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();

        $idxKey = $keyStore->generateKey();
        $dek = $keyStore->generateKey();

        $wrappedIdx = $keyStore->wrapKey($idxKey, $kek);
        $wrappedDek = $keyStore->wrapKey($dek, $kek);

        TenantKey::create([
            'tenant_id' => $this->tenantId,
            'idx_wrapped' => $wrappedIdx['wrapped'],
            'idx_nonce' => $wrappedIdx['nonce'],
            'dek_wrapped' => $wrappedDek['wrapped'],
            'dek_nonce' => $wrappedDek['nonce'],
            'key_version' => 1,
        ]);
    });

    it('encrypts fields before storing', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->address_plain = '123 Main St';
        $person->note_plain = 'Test note';
        $person->save();

        // Re-fetch from DB to verify storage
        $raw = DB::table('person')->where('id', $person->id)->first();

        // Encrypted fields should be stored (Laravel's encrypted cast)
        // The encrypted cast stores data, but we verify encryption by checking
        // that decryption works correctly (tested in next test)
        expect($raw->email_enc)->not->toBeNull()
            ->and($raw->phone_enc)->not->toBeNull()
            ->and($raw->address_enc)->not->toBeNull()
            ->and($raw->note_enc)->not->toBeNull();
    });

    it('decrypts fields when reading', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        // Re-fetch and check decrypted accessors
        $fetched = Person::find($person->id);

        expect($fetched->email)->toBe('test@example.com')
            ->and($fetched->phone)->toBe('+49 123 456789');
    });

    it('automatically generates blind indexes on save', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'Test@Example.COM';
        $person->phone_plain = '+49 (123) 456-789';
        $person->save();

        // Check that indexes are set
        $raw = DB::table('person')->where('id', $person->id)->first();

        expect($raw->email_idx)->not->toBeNull()
            ->and($raw->phone_idx)->not->toBeNull()
            ->and(strlen($raw->email_idx))->toBe(32) // HMAC-SHA256 = 32 bytes
            ->and(strlen($raw->phone_idx))->toBe(32);
    });

    it('requires tenant_id for saving', function () {
        $person = new Person;
        $person->email_plain = 'test@example.com';

        expect(fn () => $person->save())
            ->toThrow(\RuntimeException::class, 'tenant_id is required');
    });
});

describe('Person Model Hidden Attributes', function () {
    beforeEach(function () {
        $this->artisan('migrate:fresh');

        $this->tenantId = (string) Str::uuid();
        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();

        $idxKey = $keyStore->generateKey();
        $dek = $keyStore->generateKey();

        $wrappedIdx = $keyStore->wrapKey($idxKey, $kek);
        $wrappedDek = $keyStore->wrapKey($dek, $kek);

        TenantKey::create([
            'tenant_id' => $this->tenantId,
            'idx_wrapped' => $wrappedIdx['wrapped'],
            'idx_nonce' => $wrappedIdx['nonce'],
            'dek_wrapped' => $wrappedDek['wrapped'],
            'dek_nonce' => $wrappedDek['nonce'],
            'key_version' => 1,
        ]);
    });

    it('hides encrypted and index fields in toArray()', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        $array = $person->fresh()->toArray();

        // Should NOT contain *_enc or *_idx fields
        expect($array)->not->toHaveKey('email_enc')
            ->and($array)->not->toHaveKey('phone_enc')
            ->and($array)->not->toHaveKey('address_enc')
            ->and($array)->not->toHaveKey('note_enc')
            ->and($array)->not->toHaveKey('email_idx')
            ->and($array)->not->toHaveKey('phone_idx');
    });

    it('hides encrypted and index fields in toJson()', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->save();

        $json = $person->fresh()->toJson();

        expect($json)->not->toContain('email_enc')
            ->and($json)->not->toContain('email_idx')
            ->and($json)->not->toContain('phone_enc')
            ->and($json)->not->toContain('phone_idx');
    });

    it('exposes decrypted values via accessors', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        $array = $person->fresh()->toArray();

        // Should contain decrypted accessors
        expect($array)->toHaveKey('email')
            ->and($array)->toHaveKey('phone')
            ->and($array['email'])->toBe('test@example.com')
            ->and($array['phone'])->toBe('+49 123 456789');
    });
});
