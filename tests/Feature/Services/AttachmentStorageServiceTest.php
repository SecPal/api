<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\AttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
    $this->user = User::factory()->create();

    $this->service = new AttachmentStorageService;
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('service stores file with encryption', function (): void {
    $secret = new Secret;
    $secret->tenant_id = $this->tenant->id;
    $secret->owner_id = $this->user->id;
    $secret->title_plain = 'Secret with Attachment';
    $secret->save();

    $file = UploadedFile::fake()->create('test-document.pdf', 100, 'application/pdf');

    $attachment = $this->service->store($file, $secret, $this->user);

    expect($attachment)->toBeInstanceOf(SecretAttachment::class);
    expect($attachment->filename_plain)->toBe('test-document.pdf');
    expect($attachment->file_size)->toBe(102400);
    expect($attachment->mime_type)->toBe('application/pdf');
    expect($attachment->checksum_sha256)->toBeString();
    expect($attachment->storage_path)->toStartWith('attachments/');
    expect($attachment->uploaded_by)->toBe($this->user->id);
});

test('service encrypts file content in storage', function (): void {
    $secret = new Secret;
    $secret->tenant_id = $this->tenant->id;
    $secret->owner_id = $this->user->id;
    $secret->title_plain = 'Secret for Encryption Test';
    $secret->save();

    $fileContent = 'This is secret file content that must be encrypted';
    $file = UploadedFile::fake()->createWithContent('secret.txt', $fileContent);

    $attachment = $this->service->store($file, $secret, $this->user);

    $encryptedBlob = Storage::disk('local')->get($attachment->storage_path);

    $decoded = json_decode($encryptedBlob, true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('ciphertext');
    expect($decoded)->toHaveKey('nonce');
    expect($encryptedBlob)->not->toContain($fileContent);
});

test('service retrieves and decrypts file content', function (): void {
    $secret = new Secret;
    $secret->tenant_id = $this->tenant->id;
    $secret->owner_id = $this->user->id;
    $secret->title_plain = 'Secret for Decryption Test';
    $secret->save();

    $originalContent = 'Original file content to be encrypted and decrypted';
    $file = UploadedFile::fake()->createWithContent('original.txt', $originalContent);

    $attachment = $this->service->store($file, $secret, $this->user);
    $decryptedContent = $this->service->retrieve($attachment);

    expect($decryptedContent)->toBe($originalContent);
});
