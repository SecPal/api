<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeDocumentResource transforms EmployeeDocument models into API responses.
 *
 * File paths are only included for authorized users (checked at controller level).
 *
 * @mixin \App\Models\EmployeeDocument
 */
class EmployeeDocumentResource extends JsonResource
{
    /**
     * Disable wrapping for single resources.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $downloadUrl = url(sprintf('/v1/employees/%s/documents/%s/download', $this->employee_id, $this->id));

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'uploaded_by' => $this->uploaded_by,
            'title' => $this->title,
            'description' => $this->description,
            'document_type' => $this->document_type,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'download_url' => $downloadUrl,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'status' => $this->status,
            'visible_to_employee' => $this->visible_to_employee,

            // Relationships (optional)
            'uploader' => new UserResource($this->whenLoaded('uploader')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
