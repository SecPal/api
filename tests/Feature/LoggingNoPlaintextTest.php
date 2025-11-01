<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\KeyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

describe('Query & Request Logging Security', function () {
    beforeEach(function () {
        // Create tenant with keys
        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();

        $this->tenantId = '11111111-1111-1111-1111-111111111111';

        $dek = $keyStore->generateKey();
        $idxKey = $keyStore->generateKey();

        $dekWrapped = $keyStore->wrapKey($dek, $kek);
        $idxWrapped = $keyStore->wrapKey($idxKey, $kek);

        TenantKey::create([
            'tenant_id' => $this->tenantId,
            'dek_wrapped' => $dekWrapped['wrapped'],
            'dek_nonce' => $dekWrapped['nonce'],
            'idx_wrapped' => $idxWrapped['wrapped'],
            'idx_nonce' => $idxWrapped['nonce'],
            'key_version' => 1,
        ]);
    });

    test('database query log does not contain plaintext values', function () {
        // Create person with sensitive data
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'query-log-secret@example.com';
        $person->phone_plain = '+49 SECRET 12345';
        $person->address_plain = '123 Secret Street';
        $person->note_plain = 'Confidential note content';
        $person->save();

        // Enable query logging
        DB::enableQueryLog();

        // Execute queries that involve encrypted fields
        $found = Person::where('tenant_id', $this->tenantId)
            ->where('email_idx', $person->email_idx)
            ->first();

        // Access encrypted field (triggers decryption in memory, not in query)
        $email = $found->email_enc;

        // Get query log
        $queries = DB::getQueryLog();

        // Verify queries were executed
        expect($queries)->not->toBeEmpty();

        // CRITICAL: Verify NO plaintext in any query
        $allQuerySql = collect($queries)->pluck('query')->implode(' ');
        $allQueryBindings = collect($queries)->pluck('bindings')->flatten()->map(fn ($b) => is_string($b) ? $b : '')->implode(' ');

        // Should NOT contain plaintext values
        expect($allQuerySql)->not->toContain('query-log-secret@example.com');
        expect($allQuerySql)->not->toContain('SECRET 12345');
        expect($allQuerySql)->not->toContain('Secret Street');
        expect($allQuerySql)->not->toContain('Confidential note');

        expect($allQueryBindings)->not->toContain('query-log-secret@example.com');
        expect($allQueryBindings)->not->toContain('SECRET 12345');
        expect($allQueryBindings)->not->toContain('Secret Street');
        expect($allQueryBindings)->not->toContain('Confidential note');

        // Acceptable: Column names in SQL (email_enc, email_idx)
        // Not acceptable: Actual decrypted values
        expect($email)->toBe('query-log-secret@example.com'); // Sanity check

        DB::disableQueryLog();
    });

    test('query bindings use blind indexes, not plaintext', function () {
        // Create person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'binding-test@example.com';
        $person->save();

        DB::enableQueryLog();

        // Search by email (should use blind index)
        $found = Person::where('tenant_id', $this->tenantId)
            ->where('email_idx', $person->email_idx)
            ->first();

        $queries = DB::getQueryLog();

        // Verify queries were executed
        expect($queries)->not->toBeEmpty();

        // CRITICAL: Verify NO plaintext in any query SQL or bindings
        $allQuerySql = collect($queries)->pluck('query')->implode(' ');
        $allQueryBindings = collect($queries)->pluck('bindings')->flatten()->map(fn ($b) => is_string($b) ? $b : '')->implode(' ');

        expect($allQuerySql)->not->toContain('binding-test@example.com');
        expect($allQueryBindings)->not->toContain('binding-test@example.com');

        DB::disableQueryLog();
    });

    test('log context does not expose encrypted/index fields', function () {
        // Create person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'context-test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        // Capture log context
        $loggedContext = null;
        Log::shouldReceive('info')
            ->andReturnUsing(function ($message, $context) use (&$loggedContext) {
                $loggedContext = $context;
            })
            ->once();

        // Log with person context (simulating application logging)
        Log::info('Person accessed', ['person' => $person->toArray()]);

        // Verify context was logged
        expect($loggedContext)->not->toBeNull();
        expect($loggedContext)->toHaveKey('person');

        $personData = $loggedContext['person'];

        // CRITICAL: Should NOT contain encrypted/index fields (hidden by $hidden)
        expect($personData)->not->toHaveKey('email_enc');
        expect($personData)->not->toHaveKey('email_nonce');
        expect($personData)->not->toHaveKey('email_idx');
        expect($personData)->not->toHaveKey('phone_enc');
        expect($personData)->not->toHaveKey('phone_nonce');
        expect($personData)->not->toHaveKey('phone_idx');

        // Model's toArray() respects $hidden, so encrypted fields should be filtered out
    });

    test('SELECT queries never use LIKE on encrypted fields', function () {
        // Create test persons
        $person1 = new Person;
        $person1->tenant_id = $this->tenantId;
        $person1->email_plain = 'like-test-1@example.com';
        $person1->save();

        $person2 = new Person;
        $person2->tenant_id = $this->tenantId;
        $person2->email_plain = 'like-test-2@example.com';
        $person2->save();

        DB::enableQueryLog();

        // Search by partial email (should NOT be allowed via encrypted field)
        // This should use blind index with exact match, not LIKE
        $found = Person::where('tenant_id', $this->tenantId)
            ->where('email_idx', $person1->email_idx)
            ->first();

        $queries = DB::getQueryLog();

        // Verify NO LIKE queries on encrypted fields
        foreach ($queries as $query) {
            $sql = $query['query'];

            // Should NOT use LIKE on any encrypted fields
            expect($sql)->not->toMatch('/email_enc\s+(LIKE|ILIKE)/i');
            expect($sql)->not->toMatch('/phone_enc\s+(LIKE|ILIKE)/i');
            expect($sql)->not->toMatch('/address_enc\s+(LIKE|ILIKE)/i');
            expect($sql)->not->toMatch('/note_enc\s+(LIKE|ILIKE)/i');

            // LIKE on blind indexes is acceptable (but ineffective)
            // We just verify encrypted ciphertext is never used in LIKE
        }

        expect($found)->not->toBeNull();

        DB::disableQueryLog();
    });

    test('HTTP request logging does not expose encrypted fields', function () {
        // This test simulates HTTP request/response logging middleware
        // In production, a middleware would log requests/responses

        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'http-log@example.com';
        $person->save();

        // Simulate API response (PersonResource)
        $response = $person->toArray();

        // Verify response does NOT contain encrypted/index fields
        expect($response)->not->toHaveKey('email_enc');
        expect($response)->not->toHaveKey('email_nonce');
        expect($response)->not->toHaveKey('email_idx');

        // If logged, this response is safe (no encrypted/index fields)
        $logMessage = json_encode(['response' => $response]);

        // Should NOT contain internal field names
        expect($logMessage)->not->toContain('email_enc');
        expect($logMessage)->not->toContain('email_nonce');
        expect($logMessage)->not->toContain('email_idx');

        // May contain decrypted email (acceptable in API response)
        expect($logMessage)->toContain('http-log@example.com');
    });

    test('prepared statement parameters are never logged as plaintext', function () {
        // Create person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'prepared-stmt@example.com';
        $person->save();

        DB::enableQueryLog();

        // Execute parameterized query
        $results = DB::select(
            'SELECT * FROM person WHERE tenant_id = ? AND email_idx = ?',
            [$this->tenantId, $person->email_idx]
        );

        $queries = DB::getQueryLog();

        // Verify queries were executed
        expect($queries)->not->toBeEmpty();
        expect($results)->not->toBeEmpty();

        // CRITICAL: No plaintext email in any query or bindings
        $allQuerySql = collect($queries)->pluck('query')->implode(' ');
        $allQueryBindings = collect($queries)->pluck('bindings')->flatten()->map(fn ($b) => is_string($b) ? $b : '')->implode(' ');

        expect($allQuerySql)->not->toContain('prepared-stmt@example.com');
        expect($allQueryBindings)->not->toContain('prepared-stmt@example.com');

        DB::disableQueryLog();
    });

    test('mass assignment does not log plaintext in query bindings', function () {
        DB::enableQueryLog();

        // Mass assignment (triggers INSERT)
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'mass-assign@example.com'; // Will be encrypted by cast
        $person->phone_plain = '+49 111 222333';
        $person->save();

        $queries = DB::getQueryLog();

        // Verify queries were executed
        expect($queries)->not->toBeEmpty();

        // CRITICAL: No plaintext email/phone in any query or bindings
        $allQuerySql = collect($queries)->pluck('query')->implode(' ');
        $allQueryBindings = collect($queries)->pluck('bindings')->flatten()->map(fn ($b) => is_string($b) ? $b : '')->implode(' ');

        expect($allQuerySql)->not->toContain('mass-assign@example.com');
        expect($allQuerySql)->not->toContain('111 222333');
        expect($allQueryBindings)->not->toContain('mass-assign@example.com');
        expect($allQueryBindings)->not->toContain('111 222333');

        DB::disableQueryLog();
    });

    test('bulk operations do not expose plaintext in transaction logs', function () {
        DB::enableQueryLog();

        // Bulk insert
        DB::transaction(function () {
            for ($i = 1; $i <= 5; $i++) {
                $person = new Person;
                $person->tenant_id = $this->tenantId;
                $person->email_plain = "bulk-{$i}@example.com";
                $person->save();
            }
        });

        $queries = DB::getQueryLog();

        // Verify multiple INSERT queries
        $insertQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'insert into'));
        expect($insertQueries)->toHaveCount(5);

        // Check all bindings
        foreach ($insertQueries as $query) {
            $bindingsStr = collect($query['bindings'])->map(fn ($b) => is_string($b) ? $b : '')->implode(' ');

            // Should NOT contain any plaintext emails
            for ($i = 1; $i <= 5; $i++) {
                expect($bindingsStr)->not->toContain("bulk-{$i}@example.com");
            }
        }

        DB::disableQueryLog();
    });
});
