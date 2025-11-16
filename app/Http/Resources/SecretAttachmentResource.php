<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for SecretAttachment model.
 *
 * Transforms attachment metadata for JSON responses.
 *
 * @property \App\Models\SecretAttachment $resource
 * @mixin \App\Models\SecretAttachment
 */
class SecretAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\SecretAttachment $attachment */
        $attachment = $this->resource;

        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename_plain,
            'file_size' => $attachment->file_size,
            'mime_type' => $attachment->mime_type,
            'download_url' => $attachment->download_url,
            'uploaded_by' => $attachment->uploaded_by,
            'created_at' => $attachment->created_at->toIso8601String(),
        ];
    }
}
