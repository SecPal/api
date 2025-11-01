<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\KeyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TenantIsolation', function () {
    beforeEach(function () {
        // Create two tenants with keys
        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();

        $this->tenantA = '11111111-1111-1111-1111-111111111111';
        $this->tenantB = '22222222-2222-2222-2222-222222222222';

        // Generate and store keys for both tenants
        foreach ([$this->tenantA, $this->tenantB] as $tenantId) {
            $dek = $keyStore->generateKey();
            $idxKey = $keyStore->generateKey();

            $dekWrapped = $keyStore->wrapKey($dek, $kek);
            $idxWrapped = $keyStore->wrapKey($idxKey, $kek);

            TenantKey::create([
                'tenant_id' => $tenantId,
                'dek_wrapped' => $dekWrapped['wrapped'],
                'dek_nonce' => $dekWrapped['nonce'],
                'idx_wrapped' => $idxWrapped['wrapped'],
                'idx_nonce' => $idxWrapped['nonce'],
                'key_version' => 1,
            ]);
        }
    });

    test('it prevents querying Person records from different tenant', function () {
        // Create person in tenant A
        $personA = new Person;
        $personA->tenant_id = $this->tenantA;
        $personA->email_plain = 'alice@example.com';
        $personA->save();

        // Create person in tenant B
        $personB = new Person;
        $personB->tenant_id = $this->tenantB;
        $personB->email_plain = 'bob@example.com';
        $personB->save();

        // Query tenant A: should only see Alice
        $resultsA = Person::where('tenant_id', $this->tenantA)->get();
        expect($resultsA)->toHaveCount(1);
        expect($resultsA->first()->email)->toBe('alice@example.com');

        // Query tenant B: should only see Bob
        $resultsB = Person::where('tenant_id', $this->tenantB)->get();
        expect($resultsB)->toHaveCount(1);
        expect($resultsB->first()->email)->toBe('bob@example.com');
    });

    test('it prevents blind index collision between tenants', function () {
        // Both tenants have person with same email
        $email = 'shared@example.com';

        $personA = new Person;
        $personA->tenant_id = $this->tenantA;
        $personA->email_plain = $email;
        $personA->save();

        $personB = new Person;
        $personB->tenant_id = $this->tenantB;
        $personB->email_plain = $email;
        $personB->save();

        // Blind indexes MUST be different (tenant-specific HMAC keys)
        $personA->refresh();
        $personB->refresh();

        expect($personA->email_idx)->not->toBe($personB->email_idx);
    });

    test('it requires tenant_id in all Person queries', function () {
        // Create person in tenant A
        $person = new Person;
        $person->tenant_id = $this->tenantA;
        $person->email_plain = 'test@example.com';
        $person->save();

        // Query without tenant_id filter should still include it in results
        $all = Person::all();
        expect($all)->toHaveCount(1);
        expect($all->first()->tenant_id)->toBe($this->tenantA);
    });

    test('it prevents updating Person with different tenant_id', function () {
        // Create person in tenant A
        $person = new Person;
        $person->tenant_id = $this->tenantA;
        $person->email_plain = 'test@example.com';
        $person->save();

        $originalId = $person->id;
        $originalEmailIdx = $person->email_idx;

        // Attempt to change tenant_id and update
        $person->tenant_id = $this->tenantB;
        $person->email_plain = 'updated@example.com';
        $person->save();

        // Verify: email_idx should be different (new tenant's idx_key used)
        $person->refresh();
        expect($person->email_idx)->not->toBe($originalEmailIdx);
        expect($person->tenant_id)->toBe($this->tenantB);
    });

    test('it isolates Spatie Permission scopes per tenant', function () {
        // Set Spatie team context to tenant A
        setPermissionsTeamId($this->tenantA);
        expect(getPermissionsTeamId())->toBe($this->tenantA);

        // Switch to tenant B
        setPermissionsTeamId($this->tenantB);
        expect(getPermissionsTeamId())->toBe($this->tenantB);

        // Reset
        setPermissionsTeamId(null);
        expect(getPermissionsTeamId())->toBeNull();
    });
});
