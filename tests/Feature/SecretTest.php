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

/**
 * @property \App\Models\TenantKey $tenant
 * @property \App\Models\User $user
 */
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
        $secret->password_plain = 'super-secure-password';
        $secret->save();

        $secret = $secret->fresh();
        expect($secret->password_plain)->toBe('super-secure-password');
    });

    test('encrypts username_enc field using encrypted cast', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Database Credentials';
        $secret->username_plain = 'admin_user';
        $secret->save();

        $secret = $secret->fresh();
        expect($secret->username_plain)->toBe('admin_user');
    });

    test('encrypts url_enc field using encrypted cast', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'API Endpoint';
        $secret->url_plain = 'https://api.example.com/v1';
        $secret->save();

        $secret = $secret->fresh();
        expect($secret->url_plain)->toBe('https://api.example.com/v1');
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

    test('automatically generates blind index on create', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Test Secret';
        $secret->save();

        expect($secret->title_idx)->not->toBeEmpty();
        expect($secret->title_idx)->toHaveLength(44); // Base64-encoded 32-byte HMAC-SHA256 = 44 chars
    });

    test('updates blind index when title changes', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Original Title';
        $secret->save();

        $originalIndex = $secret->title_idx;

        $secret->title_plain = 'Updated Title';
        $secret->save();

        expect($secret->title_idx)->not->toBe($originalIndex);
    });

    test('blind index is consistent for same title', function (): void {
        $secret1 = new Secret;
        $secret1->tenant_id = $this->tenant->id;
        $secret1->owner_id = $this->user->id;
        $secret1->title_plain = 'Same Title';
        $secret1->save();

        $secret2 = new Secret;
        $secret2->tenant_id = $this->tenant->id;
        $secret2->owner_id = $this->user->id;
        $secret2->title_plain = 'Same Title';
        $secret2->save();

        expect($secret1->title_idx)->toBe($secret2->title_idx);
    });

    test('updates FTS vector on create', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'GitHub Token';
        $secret->notes_plain = 'Personal access token for GitHub API';
        $secret->save();

        // Verify FTS column was populated (check via raw query)
        $result = \DB::selectOne('SELECT notes_tsv FROM secrets WHERE id = ?', [$secret->id]);
        expect($result->notes_tsv)->not->toBeNull();
    });

    test('updates FTS vector on update', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Token';
        $secret->notes_plain = 'Original notes';
        $secret->save();

        $secret->notes_plain = 'Updated notes with more details';
        $secret->save();

        $result = \DB::selectOne('SELECT notes_tsv FROM secrets WHERE id = ?', [$secret->id]);
        expect($result->notes_tsv)->not->toBeNull();
    });
});

describe('Secret Model - Relationships', function () {
    test('has attachments relationship', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Test Secret';
        $secret->save();

        expect($secret->attachments())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        expect($secret->attachments)->toBeEmpty();
    });

    test('attachment_count accessor returns zero for secrets without attachments', function (): void {
        $secret = new Secret;
        $secret->tenant_id = $this->tenant->id;
        $secret->owner_id = $this->user->id;
        $secret->title_plain = 'Test Secret';
        $secret->save();

        expect($secret->attachment_count)->toBe(0);
    });
});
