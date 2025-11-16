<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\SecretAttachmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->policy = new SecretAttachmentPolicy;

    $this->secret = new Secret;
    $this->secret->tenant_id = $this->tenant->id;
    $this->secret->owner_id = $this->owner->id;
    $this->secret->title_plain = 'Test Secret';
    $this->secret->save();

    $this->attachment = new SecretAttachment;
    $this->attachment->id = \Illuminate\Support\Str::uuid();
    $this->attachment->secret_id = $this->secret->id;
    $this->attachment->tenant_id = $this->tenant->id;
    $this->attachment->filename_plain = 'test.pdf';
    $this->attachment->file_size = 1024;
    $this->attachment->mime_type = 'application/pdf';
    $this->attachment->storage_path = 'test/path.enc';
    $this->attachment->checksum_sha256 = hash('sha256', 'test');
    $this->attachment->uploaded_by = $this->owner->id;
    $this->attachment->save();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('owner can view any attachments', function (): void {
    expect($this->policy->viewAny($this->owner, $this->secret))->toBeTrue();
});

test('non-owner cannot view any attachments', function (): void {
    expect($this->policy->viewAny($this->otherUser, $this->secret))->toBeFalse();
});

test('owner can view specific attachment', function (): void {
    expect($this->policy->view($this->owner, $this->attachment))->toBeTrue();
});

test('non-owner cannot view specific attachment', function (): void {
    expect($this->policy->view($this->otherUser, $this->attachment))->toBeFalse();
});

test('owner can create attachments', function (): void {
    expect($this->policy->create($this->owner, $this->secret))->toBeTrue();
});

test('non-owner cannot create attachments', function (): void {
    expect($this->policy->create($this->otherUser, $this->secret))->toBeFalse();
});

test('owner can delete attachments', function (): void {
    expect($this->policy->delete($this->owner, $this->attachment))->toBeTrue();
});

test('non-owner cannot delete attachments', function (): void {
    expect($this->policy->delete($this->otherUser, $this->attachment))->toBeFalse();
});
