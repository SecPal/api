<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Person API Resource.
 *
 * SECURITY: Hides encrypted and blind index fields.
 * Only exposes decrypted values via accessors.
 *
 * @property-read \App\Models\Person $resource
 */
class PersonResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,

            // Decrypted fields (from accessors)
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'note' => $this->note,

            'created_at' => $this->created_at?->toIso8601String(),

            // SECURITY: Explicitly exclude encrypted and index fields
            // (Person model already hides them, but double-check here)
        ];
    }
}
