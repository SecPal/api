<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for storing, retrieving, and deleting encrypted file attachments.
 *
 * Files are encrypted using the tenant's DEK before storage.
 * Storage structure: attachments/{tenant_id}/{secret_id}/{attachment_id}.enc
 */
class AttachmentStorageService
{
    /**
     * Store an uploaded file with encryption.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  Secret  $secret  The secret to attach the file to
     * @param  User  $user  The user uploading the file
     * @return SecretAttachment The created attachment record
     */
    public function store(UploadedFile $file, Secret $secret, User $user): SecretAttachment
    {
        // Read original file content
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded file');
        }

        // Calculate checksum (before encryption)
        $checksum = hash('sha256', $content);

        // Encrypt with tenant DEK
        $tenant = $secret->tenantKey;
        if ($tenant === null) {
            throw new \RuntimeException('Secret must have an associated tenant key');
        }

        $encrypted = $tenant->encrypt($content);

        // Generate storage path
        $attachmentId = Str::uuid()->toString();
        $storagePath = sprintf(
            'attachments/%d/%s/%s.enc',
            $tenant->id,
            $secret->id,
            $attachmentId
        );

        // Store encrypted blob as JSON
        $jsonBlob = json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ]);
        if ($jsonBlob === false) {
            throw new \RuntimeException('Failed to encode encrypted data');
        }

        Storage::disk('local')->put($storagePath, $jsonBlob);

        // Create attachment record
        $attachment = new SecretAttachment;
        $attachment->id = $attachmentId;
        $attachment->secret_id = $secret->id;
        /** @var int<0, max> $tenantId */
        $tenantId = $secret->tenant_id;
        $attachment->tenant_id = $tenantId; // MUST be set BEFORE encrypted fields
        $attachment->filename_plain = $file->getClientOriginalName(); // Triggers encryption
        $attachment->file_size = $file->getSize();
        $mimeType = $file->getMimeType();
        if ($mimeType === null) {
            throw new \RuntimeException('Failed to determine file MIME type');
        }
        $attachment->mime_type = $mimeType;
        $attachment->storage_path = $storagePath;
        $attachment->checksum_sha256 = $checksum;
        $attachment->uploaded_by = $user->id;
        $attachment->save();

        return $attachment;
    }

    /**
     * Retrieve and decrypt file content.
     *
     * @param  SecretAttachment  $attachment  The attachment to retrieve
     * @return string The decrypted file content
     */
    public function retrieve(SecretAttachment $attachment): string
    {
        // Read encrypted blob from storage
        $encryptedBlob = Storage::disk('local')->get($attachment->storage_path);
        if ($encryptedBlob === null) {
            throw new \RuntimeException('Attachment file not found in storage');
        }

        $decoded = json_decode($encryptedBlob, true);
        if (! is_array($decoded) || ! isset($decoded['ciphertext'], $decoded['nonce'])) {
            throw new \RuntimeException('Invalid encrypted blob format');
        }

        if (! is_string($decoded['ciphertext']) || ! is_string($decoded['nonce'])) {
            throw new \RuntimeException('Invalid encrypted blob data types');
        }

        // Decrypt with tenant DEK
        $tenant = $attachment->secret->tenantKey;
        if ($tenant === null) {
            throw new \RuntimeException('Attachment secret must have an associated tenant key');
        }

        $decrypted = $tenant->decrypt(
            base64_decode($decoded['ciphertext']),
            base64_decode($decoded['nonce'])
        );

        return $decrypted;
    }

    /**
     * Delete attachment file from storage.
     *
     * @param  SecretAttachment  $attachment  The attachment to delete
     * @return bool True if deletion was successful
     */
    public function delete(SecretAttachment $attachment): bool
    {
        // Delete file from storage
        Storage::disk('local')->delete($attachment->storage_path);

        // Delete attachment record
        $attachment->delete();

        return true;
    }
}
