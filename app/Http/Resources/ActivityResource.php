<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for Activity model.
 *
 * Transforms activity log data with optional verification information.
 * Supports eager loading of causer, subject, and organizational unit.
 *
 * @see \App\Models\Activity
 * @see \App\Http\Controllers\Api\V1\ActivityLogController
 * @see SecPal/api#394 PR-11: ActivityLogController with scoped filtering
 * @see SecPal/api#385 Epic: Activity Logging & Audit Trail Strategy
 *
 * @phpstan-extends JsonResource<\App\Models\Activity>
 */
class ActivityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * NOTE: PHPStan doesn't understand Laravel's magic property access via $this->propertyName
     * in JsonResource classes. All property accesses are proxied to $this->resource (the Activity model).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'organizational_unit_id' => $this->organizational_unit_id,

            // Core activity data
            'log_name' => $this->log_name,
            'description' => $this->description,

            // Subject (what was changed)
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject' => $this->when(
                $this->relationLoaded('subject') && $this->subject !== null,
                function () {
                    return [
                        'type' => class_basename($this->subject_type),
                        'id' => $this->subject_id,
                        // Include basic identification fields if available
                        'name' => $this->subject->name ?? null,
                    ];
                }
            ),

            // Causer (who made the change)
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'causer' => $this->when(
                $this->relationLoaded('causer') && $this->causer !== null,
                function () {
                    return [
                        'type' => class_basename($this->causer_type),
                        'id' => $this->causer_id,
                        'name' => $this->causer->name ?? null,
                    ];
                }
            ),

            // Properties (change details)
            'properties' => $this->properties,
            'batch_uuid' => $this->batch_uuid,

            // Request metadata
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,

            // Forensic fields (hash chain)
            'previous_hash' => $this->previous_hash,
            'event_hash' => $this->event_hash,

            // Merkle tree data
            'merkle_root' => $this->merkle_root,
            'merkle_batch_id' => $this->merkle_batch_id,
            'merkle_proof' => $this->merkle_proof,

            // OpenTimestamp data
            'ots_submitted_at' => \App\Support\ApiTimestamp::nullable($this->ots_submitted_at),
            'ots_confirmed_at' => \App\Support\ApiTimestamp::nullable($this->ots_confirmed_at),
            'has_ots_proof' => $this->ots_proof !== null,

            // Orphaned genesis handling
            'is_orphaned_genesis' => $this->is_orphaned_genesis,
            'orphaned_reason' => $this->orphaned_reason,
            'orphaned_at' => \App\Support\ApiTimestamp::nullable($this->orphaned_at),

            // Timestamps
            'created_at' => \App\Support\ApiTimestamp::format($this->created_at),
            'updated_at' => \App\Support\ApiTimestamp::format($this->updated_at),

            // Relationships
            'organizational_unit' => new OrganizationalUnitResource($this->whenLoaded('organizationalUnit')),

            // Verification data (only included when explicitly requested)
            // WARNING: Verification methods perform cryptographic operations.
            // Use include_verification=1 sparingly, especially with large result sets,
            // as it calls 4 verification methods per activity (chain, chain_link, merkle, OTS).
            'verification' => $this->when(
                $request->boolean('include_verification'),
                function () {
                    return [
                        'chain_valid' => $this->verifyChain(),
                        'chain_link_valid' => $this->verifyChainLink(),
                        'merkle_valid' => $this->verifyMerkleProof(),
                        'ots_valid' => $this->verifyOpenTimestamp(),
                    ];
                }
            ),
        ];
    }
}
