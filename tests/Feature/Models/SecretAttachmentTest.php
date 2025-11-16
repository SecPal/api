<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretAttachment;
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

// Helper to create Secret with proper encryption pattern (tenant_id BEFORE title_plain)
function createTestSecret($test, array $attributes): Secret
{
    $secret = new Secret;
    $secret->tenant_id = $attributes['tenant_id'];
    $secret->owner_id = $attributes['owner_id'];
    $secret->title_plain = $attributes['title_plain'] ?? 'Test Secret';
    $secret->save();

    return $secret;
}

// Helper to create SecretAttachment with proper encryption pattern (tenant_id BEFORE filename_plain)
function createTestAttachment($test, array $attributes): SecretAttachment
{
    $attachment = new SecretAttachment;
    $attachment->secret_id = $attributes['secret_id'];
    $attachment->tenant_id = $attributes['tenant_id'];
    $attachment->filename_plain = $attributes['filename_plain'];
    $attachment->file_size = $attributes['file_size'];
    $attachment->mime_type = $attributes['mime_type'];
    $attachment->storage_path = $attributes['storage_path'];
    $attachment->checksum_sha256 = $attributes['checksum_sha256'];
    $attachment->uploaded_by = $attributes['uploaded_by'];
    $attachment->save();

    return $attachment;
}

test('secret attachment has correct fillable fields', function (): void {
    $model = new SecretAttachment;

    expect($model->getFillable())->toContain('secret_id');
    expect($model->getFillable())->toContain('filename_enc');
    expect($model->getFillable())->toContain('file_size');
    expect($model->getFillable())->toContain('mime_type');
    expect($model->getFillable())->toContain('storage_path');
    expect($model->getFillable())->toContain('checksum_sha256');
    expect($model->getFillable())->toContain('uploaded_by');
});

test('secret attachment hides encrypted fields', function (): void {
    $model = new SecretAttachment;

    expect($model->getHidden())->toContain('filename_enc');
    expect($model->getHidden())->toContain('storage_path');
});

test('secret attachment uses UUID primary key', function (): void {
    $secret = createTestSecret($this, [
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Test Secret for Attachment',
    ]);

    $attachment = createTestAttachment($this, [
        'secret_id' => $secret->id,
        'tenant_id' => $this->tenant->id,
        'filename_plain' => 'test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'storage_path' => 'attachments/test/123.enc',
        'checksum_sha256' => hash('sha256', 'test'),
        'uploaded_by' => $this->user->id,
    ]);

    expect($attachment->id)->toBeString();
    expect(strlen($attachment->id))->toBe(36); // UUID format
});

test('secret attachment encrypts filename with EncryptedWithDek cast', function (): void {
    $secret = createTestSecret($this, [
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Secret with Encrypted Attachment',
    ]);

    $attachment = createTestAttachment($this, [
        'secret_id' => $secret->id,
        'tenant_id' => $this->tenant->id,
        'filename_plain' => 'secret-document.pdf',
        'file_size' => 2048,
        'mime_type' => 'application/pdf',
        'storage_path' => 'attachments/test/456.enc',
        'checksum_sha256' => hash('sha256', 'content'),
        'uploaded_by' => $this->user->id,
    ]);

    // filename_enc should be encrypted JSON in database
    $raw = $attachment->getRawOriginal('filename_enc');
    expect($raw)->toBeString();
    expect($raw)->toContain('ciphertext');
    expect($raw)->toContain('nonce');

    // filename_plain should decrypt correctly
    expect($attachment->filename_plain)->toBe('secret-document.pdf');
});

test('secret attachment belongs to secret', function (): void {
    $secret = createTestSecret($this, [
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Secret with Relationship',
    ]);

    $attachment = createTestAttachment($this, [
        'secret_id' => $secret->id,
        'tenant_id' => $this->tenant->id,
        'filename_plain' => 'test.jpg',
        'file_size' => 512,
        'mime_type' => 'image/jpeg',
        'storage_path' => 'attachments/test/789.enc',
        'checksum_sha256' => hash('sha256', 'image'),
        'uploaded_by' => $this->user->id,
    ]);

    expect($attachment->secret)->toBeInstanceOf(Secret::class);
    expect($attachment->secret->id)->toBe($secret->id);
});

test('secret attachment belongs to user (uploaded_by)', function (): void {
    $secret = createTestSecret($this, [
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Secret for Uploader Test',
    ]);

    $attachment = createTestAttachment($this, [
        'secret_id' => $secret->id,
        'tenant_id' => $this->tenant->id,
        'filename_plain' => 'report.docx',
        'file_size' => 4096,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'storage_path' => 'attachments/test/abc.enc',
        'checksum_sha256' => hash('sha256', 'document'),
        'uploaded_by' => $this->user->id,
    ]);

    expect($attachment->uploader)->toBeInstanceOf(User::class);
    expect($attachment->uploader->id)->toBe($this->user->id);
});

test('secret attachment has download_url accessor', function (): void {
    $secret = createTestSecret($this, [
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Secret for Download URL Test',
    ]);

    $attachment = createTestAttachment($this, [
        'secret_id' => $secret->id,
        'tenant_id' => $this->tenant->id,
        'filename_plain' => 'invoice.pdf',
        'file_size' => 1536,
        'mime_type' => 'application/pdf',
        'storage_path' => 'attachments/test/def.enc',
        'checksum_sha256' => hash('sha256', 'invoice'),
        'uploaded_by' => $this->user->id,
    ]);

    expect($attachment->download_url)->toBeString();
    expect($attachment->download_url)->toContain('/api/v1/attachments/');
    expect($attachment->download_url)->toContain($attachment->id);
    expect($attachment->download_url)->toContain('/download');
});
