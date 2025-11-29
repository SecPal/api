<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for Customer model.
 *
 * Transforms Customer model data for API responses.
 * Note: managedBy relationship is only included for internal employees,
 * not for customer users (Client role).
 *
 * @mixin \App\Models\Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isCustomerUser = $user?->hasRole('Client') ?? false;

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'customer_number' => $this->customer_number,
            'type' => $this->type,
            'address' => $this->address,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'metadata' => $this->metadata,
            'parent' => new CustomerResource($this->whenLoaded('parent')),
            'children' => CustomerResource::collection($this->whenLoaded('children')),
            'ancestors' => CustomerResource::collection($this->whenLoaded('ancestors')),
            'descendants' => CustomerResource::collection($this->whenLoaded('descendants')),
            'objects' => SecPalObjectResource::collection($this->whenLoaded('objects')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];

        // Only include managedBy for internal employees
        if (! $isCustomerUser) {
            $data['managed_by'] = new OrganizationalUnitResource($this->whenLoaded('managedBy'));
        }

        return $data;
    }
}
