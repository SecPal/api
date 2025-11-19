<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\User;

/**
 * Authorization policy for SecretAttachment model.
 *
 * Attachment permissions are derived from Secret permissions:
 * - viewAny/view: Owner OR has read+ permission via share
 * - create: Owner OR has write+ permission via share
 * - delete: Owner OR has write+ permission via share
 *
 * Permission hierarchy: admin > write > read
 * - read: View secret + download attachments
 * - write: read + update secret + upload/delete attachments
 * - admin: write + delete secret + manage shares
 */
class SecretAttachmentPolicy
{
    /**
     * Determine if user can view any attachments for a secret.
     *
     * Requires read+ permission (read, write, or admin).
     */
    public function viewAny(User $user, Secret $secret): bool
    {
        return $secret->userHasPermission($user, 'read');
    }

    /**
     * Determine if user can view a specific attachment.
     *
     * Requires read+ permission (read, write, or admin) on the secret.
     */
    public function view(User $user, SecretAttachment $attachment): bool
    {
        return $attachment->secret->userHasPermission($user, 'read');
    }

    /**
     * Determine if user can upload attachments to a secret.
     *
     * Requires write+ permission (write or admin).
     */
    public function create(User $user, Secret $secret): bool
    {
        return $secret->userHasPermission($user, 'write');
    }

    /**
     * Determine if user can delete an attachment.
     *
     * Requires write+ permission (write or admin) on the secret.
     */
    public function delete(User $user, SecretAttachment $attachment): bool
    {
        return $attachment->secret->userHasPermission($user, 'write');
    }
}
