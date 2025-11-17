<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretShare;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\SecretPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->policy = new SecretPolicy;

    $this->secret = new Secret;
    $this->secret->tenant_id = $this->tenant->id;
    $this->secret->owner_id = $this->owner->id;
    $this->secret->title_plain = 'Test Secret';
    $this->secret->save();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('SecretPolicy - Basic Permissions', function () {
    test('owner can restore secret', function (): void {
        expect($this->policy->restore($this->owner, $this->secret))->toBeTrue();
    });

    test('non-owner cannot restore secret', function (): void {
        expect($this->policy->restore($this->otherUser, $this->secret))->toBeFalse();
    });

    test('owner can force delete secret', function (): void {
        expect($this->policy->forceDelete($this->owner, $this->secret))->toBeTrue();
    });

    test('non-owner cannot force delete secret', function (): void {
        expect($this->policy->forceDelete($this->otherUser, $this->secret))->toBeFalse();
    });
});

describe('SecretPolicy - Share Permissions', function () {
    test('owner can share secret', function (): void {
        expect($this->policy->share($this->owner, $this->secret))->toBeTrue();
    });

    test('non-owner cannot share secret', function (): void {
        expect($this->policy->share($this->otherUser, $this->secret))->toBeFalse();
    });

    test('user with admin share can share secret', function (): void {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'admin',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        expect($this->policy->share($this->otherUser, $this->secret))->toBeTrue();
    });

    test('user with write share cannot share secret', function (): void {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'write',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        expect($this->policy->share($this->otherUser, $this->secret))->toBeFalse();
    });

    test('owner can view shares', function (): void {
        expect($this->policy->viewShares($this->owner, $this->secret))->toBeTrue();
    });

    test('non-owner cannot view shares', function (): void {
        expect($this->policy->viewShares($this->otherUser, $this->secret))->toBeFalse();
    });

    test('user with admin share can view shares', function (): void {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'admin',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        expect($this->policy->viewShares($this->otherUser, $this->secret))->toBeTrue();
    });

    test('user with read share cannot view shares', function (): void {
        SecretShare::create([
            'secret_id' => $this->secret->id,
            'user_id' => $this->otherUser->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
            'granted_at' => now(),
        ]);

        expect($this->policy->viewShares($this->otherUser, $this->secret))->toBeFalse();
    });
});
