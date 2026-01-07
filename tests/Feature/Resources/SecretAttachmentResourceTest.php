<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Resources\SecretAttachmentResource;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create tenant
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Create user
    $this->user = User::factory()->create();
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('resource transforms single attachment correctly', function () {
    $secret = createTestSecret([
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Test Secret',
    ]);

    $attachment = createTestAttachment([
        'tenant_id' => $this->tenant->id,
        'secret_id' => $secret->id,
        'uploaded_by' => $this->user->id,
        'filename_plain' => 'test-document.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'storage_path' => 'test-storage-path',
        'checksum_sha256' => hash('sha256', 'test-content'),
    ]);

    $resource = SecretAttachmentResource::make($attachment);
    $array = $resource->toArray(request());

    expect($array)->toHaveKeys([
        'id',
        'filename',
        'file_size',
        'mime_type',
        'download_url',
        'uploaded_by',
        'created_at',
    ]);

    expect($array['id'])->toBe($attachment->id);
    expect($array['filename'])->toBe('test-document.pdf');
    expect($array['file_size'])->toBe(1024);
    expect($array['mime_type'])->toBe('application/pdf');
    expect($array['uploaded_by'])->toBe($this->user->id);
    expect($array['download_url'])->toBeString();
    expect($array['created_at'])->toBeString();
});

test('resource transforms collection of attachments correctly', function () {
    $secret = createTestSecret([
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Test Secret',
    ]);

    $attachment1 = createTestAttachment([
        'tenant_id' => $this->tenant->id,
        'secret_id' => $secret->id,
        'uploaded_by' => $this->user->id,
        'filename_plain' => 'file1.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'storage_path' => 'test-storage-path-1',
        'checksum_sha256' => hash('sha256', 'test-content-1'),
    ]);

    $attachment2 = createTestAttachment([
        'tenant_id' => $this->tenant->id,
        'secret_id' => $secret->id,
        'uploaded_by' => $this->user->id,
        'filename_plain' => 'file2.pdf',
        'file_size' => 2048,
        'mime_type' => 'application/pdf',
        'storage_path' => 'test-storage-path-2',
        'checksum_sha256' => hash('sha256', 'test-content-2'),
    ]);

    $collection = SecretAttachmentResource::collection(collect([$attachment1, $attachment2]));
    $array = $collection->toArray(request());

    expect($array)->toHaveCount(2);
    expect($array[0]['filename'])->toBe('file1.pdf');
    expect($array[1]['filename'])->toBe('file2.pdf');
});

test('resource formats created_at as ISO8601 string', function () {
    $secret = createTestSecret([
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Test Secret',
    ]);

    $attachment = createTestAttachment([
        'tenant_id' => $this->tenant->id,
        'secret_id' => $secret->id,
        'uploaded_by' => $this->user->id,
        'filename_plain' => 'test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'storage_path' => 'test-storage-path',
        'checksum_sha256' => hash('sha256', 'test-content'),
    ]);

    $resource = SecretAttachmentResource::make($attachment);
    $array = $resource->toArray(request());

    // ISO 8601 format: 2025-11-16T15:30:00+00:00
    expect($array['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('resource decrypts filename_plain correctly', function () {
    $secret = createTestSecret([
        'tenant_id' => $this->tenant->id,
        'owner_id' => $this->user->id,
        'title_plain' => 'Test Secret',
    ]);

    $attachment = createTestAttachment([
        'tenant_id' => $this->tenant->id,
        'secret_id' => $secret->id,
        'uploaded_by' => $this->user->id,
        'filename_plain' => 'sensitive-document.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'storage_path' => 'test-storage-path',
        'checksum_sha256' => hash('sha256', 'test-content'),
    ]);

    $resource = SecretAttachmentResource::make($attachment);
    $array = $resource->toArray(request());

    // Verify decryption works (filename should be decrypted)
    expect($array['filename'])->toBe('sensitive-document.pdf');
});
