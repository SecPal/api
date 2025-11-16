<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('CreateSecretSharesTable Migration', function () {
    test('creates secret_shares table with correct columns', function (): void {
        expect(Schema::hasTable('secret_shares'))->toBeTrue();

        expect(Schema::hasColumn('secret_shares', 'id'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'secret_id'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'user_id'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'role_id'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'permission'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'granted_by'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'granted_at'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'expires_at'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'created_at'))->toBeTrue();
        expect(Schema::hasColumn('secret_shares', 'updated_at'))->toBeTrue();
    });

    test('has indexes on key columns', function (): void {
        $indexes = Schema::getIndexes('secret_shares');
        $indexColumns = collect($indexes)->pluck('columns')->flatten()->toArray();

        expect($indexColumns)->toContain('secret_id');
        expect($indexColumns)->toContain('user_id');
        expect($indexColumns)->toContain('role_id');
        expect($indexColumns)->toContain('expires_at');
    });

    test('has unique constraints for secret_id with user_id and role_id', function (): void {
        $indexes = Schema::getIndexes('secret_shares');

        // Check for unique constraint on secret_id + user_id
        $hasUserUnique = collect($indexes)->contains(function ($index) {
            return $index['unique']
                && in_array('secret_id', $index['columns'])
                && in_array('user_id', $index['columns']);
        });

        // Check for unique constraint on secret_id + role_id
        $hasRoleUnique = collect($indexes)->contains(function ($index) {
            return $index['unique']
                && in_array('secret_id', $index['columns'])
                && in_array('role_id', $index['columns']);
        });

        expect($hasUserUnique)->toBeTrue();
        expect($hasRoleUnique)->toBeTrue();
    });
});
