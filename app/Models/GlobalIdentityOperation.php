<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Services\GlobalIdentity\GlobalIdentityDeliveryState;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property GlobalIdentityOperationType $operation_type
 * @property ?string $user_id
 * @property ?int $email_version
 * @property ?string $invitation_id
 * @property ?int $tenant_id
 * @property CarbonImmutable $expires_at
 * @property ?string $token_hash
 * @property ?array<mixed> $delivery_token_enc
 * @property ?array<mixed> $recipient_enc
 * @property string $delivery_key_id
 * @property int $delivery_key_version
 * @property GlobalIdentityDeliveryState $delivery_state
 * @property ?string $delivery_attempt_id
 * @property int $delivery_fence_version
 * @property int $delivery_attempt_count
 * @property ?CarbonImmutable $lease_expires_at
 * @property ?CarbonImmutable $dispatch_committed_at
 * @property ?string $transport_identity
 * @property bool $uses_idempotency_key
 * @property ?string $provider_message_reference
 * @property ?string $terminal_reason
 * @property ?string $incident_id
 * @property ?string $replaces_operation_id
 * @property bool $quarantined
 */
final class GlobalIdentityOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $visible = ['id', 'operation_type', 'delivery_state', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation_type' => GlobalIdentityOperationType::class,
            'email_version' => 'integer',
            'tenant_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'delivery_token_enc' => 'array',
            'recipient_enc' => 'array',
            'delivery_key_version' => 'integer',
            'delivery_state' => GlobalIdentityDeliveryState::class,
            'delivery_fence_version' => 'integer',
            'delivery_attempt_count' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'dispatch_committed_at' => 'immutable_datetime',
            'uses_idempotency_key' => 'boolean',
            'quarantined' => 'boolean',
        ];
    }

    public function context(): GlobalIdentityOperationContext
    {
        return new GlobalIdentityOperationContext(
            $this->operation_type,
            $this->id,
            $this->user_id,
            $this->email_version,
            $this->invitation_id,
            $this->tenant_id,
        );
    }
}
