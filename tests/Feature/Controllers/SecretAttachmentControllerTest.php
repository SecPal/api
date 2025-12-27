<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @property \App\Models\TenantKey $tenant
 * @property \App\Models\User $user
 * @property \App\Models\Secret $secret
 */
beforeEach(function (): void {
    Storage::fake('local');

    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');

    $this->secret = new Secret;
    $this->secret->tenant_id = $this->tenant->id;
    $this->secret->owner_id = $this->user->id;
    $this->secret->title_plain = 'Test Secret';
    $this->secret->save();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('POST /v1/secrets/{secret}/attachments - Upload', function () {
    test('uploads file successfully', function (): void {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'filename',
                    'file_size',
                    'mime_type',
                    'download_url',
                    'uploaded_by',
                    'created_at',
                ],
            ]);

        // Verify attachment created in DB
        $this->assertDatabaseHas('secret_attachments', [
            'secret_id' => $this->secret->id,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->user->id,
        ]);

        // Verify file encrypted in storage
        $attachment = SecretAttachment::latest()->first();
        Storage::disk('local')->assertExists($attachment->storage_path);
    });

    test('validates file is required', function (): void {
        $response = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    });

    test('validates file size limit', function (): void {
        $maxSize = config('attachments.max_file_size');
        $file = UploadedFile::fake()->create('large.pdf', ($maxSize / 1024) + 1, 'application/pdf');

        $response = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    });

    test('validates allowed mime types', function (): void {
        $file = UploadedFile::fake()->create('executable.exe', 100, 'application/x-msdownload');

        $response = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    });

    test('requires authorization (non-owner)', function (): void {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(403);
    });
});

describe('GET /v1/secrets/{secret}/attachments - List', function () {
    test('lists attachments for secret', function (): void {
        // Create 2 attachments
        $att1 = new SecretAttachment;
        $att1->id = \Illuminate\Support\Str::uuid();
        $att1->secret_id = $this->secret->id;
        $att1->tenant_id = $this->tenant->id;
        $att1->filename_plain = 'file1.pdf';
        $att1->file_size = 1024;
        $att1->mime_type = 'application/pdf';
        $att1->storage_path = 'test/path1.enc';
        $att1->checksum_sha256 = hash('sha256', 'test1');
        $att1->uploaded_by = $this->user->id;
        $att1->save();

        $att2 = new SecretAttachment;
        $att2->id = \Illuminate\Support\Str::uuid();
        $att2->secret_id = $this->secret->id;
        $att2->tenant_id = $this->tenant->id;
        $att2->filename_plain = 'file2.jpg';
        $att2->file_size = 2048;
        $att2->mime_type = 'image/jpeg';
        $att2->storage_path = 'test/path2.enc';
        $att2->checksum_sha256 = hash('sha256', 'test2');
        $att2->uploaded_by = $this->user->id;
        $att2->save();

        $response = $this->getJson("/v1/secrets/{$this->secret->id}/attachments");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'filename', 'file_size', 'mime_type', 'download_url', 'created_at'],
                ],
            ]);
    });

    test('returns empty array when no attachments', function (): void {
        $response = $this->getJson("/v1/secrets/{$this->secret->id}/attachments");

        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    });

    test('requires authorization (non-owner)', function (): void {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');

        $response = $this->getJson("/v1/secrets/{$this->secret->id}/attachments");

        $response->assertStatus(403);
    });
});

describe('GET /v1/attachments/{attachment}/download - Download', function () {
    test('downloads attachment with correct headers', function (): void {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        // Upload first
        $uploadResponse = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", [
            'file' => $file,
        ]);

        $attachmentId = $uploadResponse->json('data.id');

        // Download
        $response = $this->get("/v1/attachments/{$attachmentId}/download");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="document.pdf"');
    });

    test('requires authorization (non-owner)', function (): void {
        $attachment = new SecretAttachment;
        $attachment->id = \Illuminate\Support\Str::uuid();
        $attachment->secret_id = $this->secret->id;
        $attachment->tenant_id = $this->tenant->id;
        $attachment->filename_plain = 'test.pdf';
        $attachment->file_size = 1024;
        $attachment->mime_type = 'application/pdf';
        $attachment->storage_path = 'test/path.enc';
        $attachment->checksum_sha256 = hash('sha256', 'test');
        $attachment->uploaded_by = $this->user->id;
        $attachment->save();

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');

        $response = $this->get("/v1/attachments/{$attachment->id}/download");

        $response->assertStatus(403);
    });
});

describe('DELETE /v1/attachments/{attachment} - Delete', function () {
    test('deletes attachment successfully', function (): void {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        // Upload first
        $uploadResponse = $this->postJson("/v1/secrets/{$this->secret->id}/attachments", [
            'file' => $file,
        ]);

        $attachmentId = $uploadResponse->json('data.id');

        // Delete
        $response = $this->deleteJson("/v1/attachments/{$attachmentId}");

        $response->assertStatus(204);

        // Verify deleted from DB
        $this->assertDatabaseMissing('secret_attachments', [
            'id' => $attachmentId,
        ]);
    });

    test('deletes attachment and verifies cascade', function (): void {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        Storage::put('test/path.enc', 'encrypted content');

        $attachment = new SecretAttachment;
        $attachment->id = \Illuminate\Support\Str::uuid();
        $attachment->secret_id = $this->secret->id;
        $attachment->tenant_id = $this->tenant->id;
        $attachment->filename_plain = 'document.pdf';
        $attachment->file_size = 100;
        $attachment->mime_type = 'application/pdf';
        $attachment->storage_path = 'test/path.enc';
        $attachment->checksum_sha256 = hash('sha256', 'test');
        $attachment->uploaded_by = $this->user->id;
        $attachment->save();

        $response = $this->deleteJson("/v1/attachments/{$attachment->id}");

        $response->assertStatus(204);
        Storage::assertMissing($attachment->storage_path);
    });

    test('requires authorization (non-owner)', function (): void {
        $attachment = new SecretAttachment;
        $attachment->id = \Illuminate\Support\Str::uuid();
        $attachment->secret_id = $this->secret->id;
        $attachment->tenant_id = $this->tenant->id;
        $attachment->filename_plain = 'test.pdf';
        $attachment->file_size = 1024;
        $attachment->mime_type = 'application/pdf';
        $attachment->storage_path = 'test/path.enc';
        $attachment->checksum_sha256 = hash('sha256', 'test');
        $attachment->uploaded_by = $this->user->id;
        $attachment->save();

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');

        $response = $this->deleteJson("/v1/attachments/{$attachment->id}");

        $response->assertStatus(403);
    });
});
