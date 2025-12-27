<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Person;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @property TenantKey $tenant
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('No Plaintext in Database', function () {
    test('encrypted fields do not contain plaintext in database', function (): void {
        $testEmail = 'sensitive@example.com';
        $testPhone = '+49 123 456789';
        $testNote = 'Very sensitive information';

        // Create person with sensitive data
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = $testEmail;
        $person->phone_plain = $testPhone;
        $person->note_enc = $testNote;
        $person->save();

        // Query raw database row
        $rawRow = DB::table('person')
            ->where('id', $person->id)
            ->first();

        // Check email_enc does not contain plaintext
        expect($rawRow->email_enc)->not->toContain($testEmail);
        expect($rawRow->email_enc)->not->toContain('sensitive');
        expect($rawRow->email_enc)->toContain('ciphertext'); // Should be JSON format
        expect($rawRow->email_enc)->toContain('nonce');

        // Check phone_enc does not contain plaintext
        expect($rawRow->phone_enc)->not->toContain($testPhone);
        expect($rawRow->phone_enc)->not->toContain('123');
        expect($rawRow->phone_enc)->toContain('ciphertext');
        expect($rawRow->phone_enc)->toContain('nonce');

        // Check note_enc does not contain plaintext
        expect($rawRow->note_enc)->not->toContain($testNote);
        expect($rawRow->note_enc)->not->toContain('sensitive');
        expect($rawRow->note_enc)->toContain('ciphertext');
        expect($rawRow->note_enc)->toContain('nonce');
    });

    test('blind indexes do not contain plaintext', function (): void {
        $testEmail = 'testuser@example.com';
        $testPhone = '+49 987 654321';

        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = $testEmail;
        $person->phone_plain = $testPhone;
        $person->save();

        $rawRow = DB::table('person')
            ->where('id', $person->id)
            ->first();

        // Blind indexes should be base64-encoded hashes, not plaintext
        expect($rawRow->email_idx)->not->toContain($testEmail);
        expect($rawRow->email_idx)->not->toContain('testuser');
        expect($rawRow->email_idx)->not->toContain('@');
        expect($rawRow->email_idx)->not->toContain('example.com');

        expect($rawRow->phone_idx)->not->toContain($testPhone);
        expect($rawRow->phone_idx)->not->toContain('987');
        expect($rawRow->phone_idx)->not->toContain('654321');

        // Should be base64 (only alphanumeric + / + =)
        expect($rawRow->email_idx)->toMatch('/^[A-Za-z0-9+\/=]+$/');
        expect($rawRow->phone_idx)->toMatch('/^[A-Za-z0-9+\/=]+$/');
    });

    test('tenant keys are wrapped and not in plaintext', function (): void {
        $rawRow = DB::table('tenant_keys')
            ->where('id', $this->tenant->id)
            ->first();

        // Wrapped keys should be base64-encoded ciphertext
        expect($rawRow->dek_wrapped)->toMatch('/^[A-Za-z0-9+\/=]+$/');
        expect($rawRow->idx_wrapped)->toMatch('/^[A-Za-z0-9+\/=]+$/');

        // Nonces should be base64
        expect($rawRow->dek_nonce)->toMatch('/^[A-Za-z0-9+\/=]+$/');
        expect($rawRow->idx_nonce)->toMatch('/^[A-Za-z0-9+\/=]+$/');

        // Should not be trivially short (must be actual encrypted data)
        expect(strlen($rawRow->dek_wrapped))->toBeGreaterThan(40);
        expect(strlen($rawRow->idx_wrapped))->toBeGreaterThan(40);
    });
});

describe('No Plaintext in Logs', function () {
    test('person creation does not log plaintext PII', function (): void {
        $testEmail = 'secret-email@example.com';
        $testPhone = '+49 555 1234567';
        $testNote = 'Confidential information';

        // Capture log output
        $logMessages = [];
        Log::listen(function ($log) use (&$logMessages) {
            $logMessages[] = $log->message;
        });

        // Create person
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = $testEmail;
        $person->phone_plain = $testPhone;
        $person->note_enc = $testNote;
        $person->save();

        // Check logs do not contain plaintext
        $allLogs = implode(' ', $logMessages);
        expect($allLogs)->not->toContain($testEmail);
        expect($allLogs)->not->toContain('secret-email');
        expect($allLogs)->not->toContain($testPhone);
        expect($allLogs)->not->toContain('555');
        expect($allLogs)->not->toContain($testNote);
        expect($allLogs)->not->toContain('Confidential');
    });

    test('person update does not log plaintext PII', function (): void {
        $originalEmail = 'original@example.com';
        $newEmail = 'updated-secret@example.com';

        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = $originalEmail;
        $person->save();

        // Capture log output for update
        $logMessages = [];
        Log::listen(function ($log) use (&$logMessages) {
            $logMessages[] = $log->message;
        });

        $person->email_plain = $newEmail;
        $person->save();

        // Check logs do not contain plaintext
        $allLogs = implode(' ', $logMessages);
        expect($allLogs)->not->toContain($newEmail);
        expect($allLogs)->not->toContain('updated-secret');
    });

    test('exception messages do not expose plaintext', function (): void {
        $sensitiveEmail = 'confidential@example.com';

        try {
            // Attempt to create invalid person (missing tenant_id should fail)
            $person = new Person;
            $person->email_plain = $sensitiveEmail;
            $person->save(); // Should throw exception
        } catch (\Exception $e) {
            // Exception message should not contain the sensitive email
            expect($e->getMessage())->not->toContain($sensitiveEmail);
            expect($e->getMessage())->not->toContain('confidential');
        }
    });
});

describe('API Response Security', function () {
    test('JSON responses do not expose encrypted fields or indexes', function (): void {
        $person = new Person;
        $person->tenant_id = $this->tenant->id;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->note_enc = 'Secret note';
        $person->save();

        $jsonArray = $person->toArray();

        // Encrypted fields should be hidden
        expect($jsonArray)->not->toHaveKey('email_enc');
        expect($jsonArray)->not->toHaveKey('phone_enc');
        expect($jsonArray)->not->toHaveKey('note_enc');

        // Blind indexes should be hidden
        expect($jsonArray)->not->toHaveKey('email_idx');
        expect($jsonArray)->not->toHaveKey('phone_idx');

        // Only safe fields should be present
        expect($jsonArray)->toHaveKeys(['id', 'tenant_id', 'created_at', 'updated_at']);
    });
});
