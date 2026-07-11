<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for OrganizationalUnit model.
 *
 * Transforms OrganizationalUnit model data for API responses.
 * Includes hierarchical relationships when loaded.
 *
 * When `accessible_unit_ids` is set in the request attributes,
 * parent data is filtered to only include accessible units
 * (Need-to-Know principle).
 *
 * @mixin \App\Models\OrganizationalUnit
 */
class OrganizationalUnitResource extends JsonResource
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
            'type' => $this->type,
            'name' => $this->name,
            'custom_type_name' => $this->custom_type_name,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'is_legal_entity' => $this->is_legal_entity,
            'is_establishment' => $this->is_establishment,
            'is_active' => $this->is_active,
            'is_assignable' => $this->is_assignable,
            'parent' => $this->transformParent($request),
            'permissions' => $this->resolvePermissions($request),
            'children' => OrganizationalUnitResource::collection($this->whenLoaded('children')),
            'ancestors' => OrganizationalUnitResource::collection($this->whenLoaded('ancestors')),
            'descendants' => OrganizationalUnitResource::collection($this->whenLoaded('descendants')),
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),
        ];
    }

    /**
     * Transform parent relationship with Need-to-Know filtering.
     *
     * If `accessible_unit_ids` is set in request attributes,
     * only return parent if it's in the accessible list.
     */
    private function transformParent(Request $request): ?OrganizationalUnitResource
    {
        if (! $this->relationLoaded('parent') || $this->parent === null) {
            return null;
        }

        // Check if Need-to-Know filtering is active
        /** @var array<string>|null $accessibleIds */
        $accessibleIds = $request->attributes->get('accessible_unit_ids');

        if ($accessibleIds !== null && ! in_array($this->parent->id, $accessibleIds, true)) {
            // Parent exists but is not accessible - return null
            return null;
        }

        return new OrganizationalUnitResource($this->parent);
    }

    /**
     * Resolve action permissions for the authenticated user.
     *
     * @return array{create_child: bool, update: bool, delete: bool, manage_scopes: bool}
     */
    private function resolvePermissions(Request $request): array
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        return [
            'create_child' => $user?->can('create', $this->resource) ?? false,
            'update' => $user?->can('update', $this->resource) ?? false,
            'delete' => $user?->can('delete', $this->resource) ?? false,
            'manage_scopes' => $user?->can('manageScopes', $this->resource) ?? false,
        ];
    }
}
