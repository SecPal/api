<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PersonResource transforms Person models into API responses.
 *
 * Only exposes safe fields (id, tenant_id, timestamps).
 * Encrypted fields and blind indexes are never exposed.
 *
 * @mixin \App\Models\Person
 */
class PersonResource extends JsonResource
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
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
        ];
    }
}
