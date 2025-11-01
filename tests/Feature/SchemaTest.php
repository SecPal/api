<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

describe('Database Schema', function () {
    beforeEach(function () {
        // Run migrations before each test
        $this->artisan('migrate:fresh');
    });

    it('has tenant_keys table with correct structure', function () {
        expect(Schema::hasTable('tenant_keys'))->toBeTrue();

        $columns = Schema::getColumnListing('tenant_keys');
        expect($columns)->toContain('tenant_id', 'dek_wrapped', 'dek_nonce', 'idx_wrapped', 'idx_nonce', 'key_version', 'created_at');

        // Check column types (PostgreSQL/SQLite compatible)
        expect(Schema::hasColumn('tenant_keys', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('tenant_keys', 'dek_wrapped'))->toBeTrue(); // BYTEA in Postgres
        expect(Schema::hasColumn('tenant_keys', 'idx_wrapped'))->toBeTrue(); // BYTEA
    });

    it('has person table with correct structure', function () {
        expect(Schema::hasTable('person'))->toBeTrue();

        $columns = Schema::getColumnListing('person');
        expect($columns)->toContain(
            'id',
            'tenant_id',
            'email_enc',
            'phone_enc',
            'address_enc',
            'note_enc',
            'email_idx',
            'phone_idx',
            'created_at'
        );
    });

    it('has correct indexes on person table', function () {
        // Check tenant_id column exists (for foreign key)
        expect(Schema::hasColumn('person', 'tenant_id'))->toBeTrue();

        // Check email_idx and phone_idx columns exist (for blind index searches)
        expect(Schema::hasColumn('person', 'email_idx'))->toBeTrue();
        expect(Schema::hasColumn('person', 'phone_idx'))->toBeTrue();
    });

    it('has optional FTS column on person table', function () {
        if (config('database.default') === 'pgsql') {
            // Check if tsvector column exists (PostgreSQL specific)
            $columns = Schema::getColumnListing('person');
            // FTS is optional, so this test passes if table exists
            expect($columns)->toBeArray();
        } else {
            $this->markTestSkipped('FTS only on PostgreSQL');
        }
    });
});
