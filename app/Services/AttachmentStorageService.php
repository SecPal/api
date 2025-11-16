<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
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

        // Calculate checksum (before encryption)
        $checksum = hash('sha256', $content);

        // Encrypt with tenant DEK
        $tenant = $secret->tenantKey;
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
        Storage::disk('local')->put($storagePath, json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ]));

        // Create attachment record
        $attachment = new SecretAttachment();
        $attachment->id = $attachmentId;
        $attachment->secret_id = $secret->id;
        $attachment->tenant_id = $secret->tenant_id; // MUST be set BEFORE encrypted fields
        $attachment->filename_plain = $file->getClientOriginalName(); // Triggers encryption
        $attachment->file_size = $file->getSize();
        $attachment->mime_type = $file->getMimeType();
        $attachment->storage_path = $storagePath;
        $attachment->checksum_sha256 = $checksum;
        $attachment->uploaded_by = $user->id;
        $attachment->save();

        return $attachment;
    }    /**
     * Retrieve and decrypt file content.
     *
     * @param  SecretAttachment  $attachment  The attachment to retrieve
     * @return string The decrypted file content
     */
    public function retrieve(SecretAttachment $attachment): string
    {
        // Read encrypted blob from storage
        $encryptedBlob = Storage::disk('local')->get($attachment->storage_path);
        $decoded = json_decode($encryptedBlob, true);

        // Decrypt with tenant DEK
        $tenant = $attachment->secret->tenantKey;
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
        return $attachment->delete();
    }
}
