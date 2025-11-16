<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Secret;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create user
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('Secret Model - Encrypted Casts', function () {
    test('encrypts title_enc field using encrypted cast', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'GitHub Access Token';
        $secret->save();

        $secret = $secret->fresh();
        expect($secret->title_plain)->toBe('GitHub Access Token');
    });

    test('encrypts password_enc field using encrypted cast', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'My Secret';
        $secret->password_plain = 'super_secret_password_123';
        $secret->save();

        $secret = $secret->fresh();
        expect($secret->password_plain)->toBe('super_secret_password_123');
    });

    test('optional fields can be null', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Minimal Secret';
        $secret->save();

        $secret = $secret->fresh();
        expect($secret->username_plain)->toBeNull();
        expect($secret->password_plain)->toBeNull();
        expect($secret->url_plain)->toBeNull();
        expect($secret->notes_plain)->toBeNull();
    });
});

describe('Secret Model - Hidden Fields', function () {
    test('hides encrypted fields from JSON', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'My Secret';
        $secret->password_plain = 'password123';
        $secret->save();

        $json = $secret->toArray();

        expect($json)->not->toHaveKey('title_enc');
        expect($json)->not->toHaveKey('title_idx');
        expect($json)->not->toHaveKey('password_enc');
        expect($json)->not->toHaveKey('notes_tsv');
    });
});
