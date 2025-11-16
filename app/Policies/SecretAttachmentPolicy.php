<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Policies;

use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\User;

/**
 * Authorization policy for SecretAttachment model.
 *
 * Attachment permissions are derived from Secret ownership:
 * - viewAny/view: Secret owner only
 * - create: Secret owner only
 * - delete: Secret owner only
 *
 * TODO: Extend with sharing permissions when Secret sharing is implemented
 */
class SecretAttachmentPolicy
{
    /**
     * Determine if user can view any attachments for a secret.
     */
    public function viewAny(User $user, Secret $secret): bool
    {
        return $user->id === $secret->owner_id;
    }

    /**
     * Determine if user can view a specific attachment.
     */
    public function view(User $user, SecretAttachment $attachment): bool
    {
        return $user->id === $attachment->secret->owner_id;
    }

    /**
     * Determine if user can upload attachments to a secret.
     */
    public function create(User $user, Secret $secret): bool
    {
        return $user->id === $secret->owner_id;
    }

    /**
     * Determine if user can delete an attachment.
     */
    public function delete(User $user, SecretAttachment $attachment): bool
    {
        return $user->id === $attachment->secret->owner_id;
    }
}
