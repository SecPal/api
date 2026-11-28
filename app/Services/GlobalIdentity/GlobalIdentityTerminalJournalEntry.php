<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;

final readonly class GlobalIdentityTerminalJournalEntry
{
    public function __construct(
        public int $sequence,
        public string $operationId,
        public GlobalIdentityDeliveryState $state,
        public GlobalIdentityKeyReference $deliveryKey,
        public int $recordedAt,
        public ?string $incidentReference = null,
    ) {
        if ($sequence < 1 || $recordedAt < 1 || ! GlobalIdentityOperationContext::isCanonicalId($operationId)
            || ! in_array($state, [GlobalIdentityDeliveryState::Delivered, GlobalIdentityDeliveryState::Terminal], true)
            || ! GlobalIdentityOperationContext::isCanonicalId($deliveryKey->id)
            || ($incidentReference !== null && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $incidentReference) !== 1)) {
            throw new GlobalIdentityKeyException('Invalid global identity terminal journal entry.');
        }
    }

    public function canonicalBytes(): string
    {
        return GlobalIdentityCanonicalEncoding::encode(
            (string) $this->sequence,
            $this->operationId,
            $this->state->value,
            $this->deliveryKey->id,
            (string) $this->deliveryKey->version,
            (string) $this->recordedAt,
            $this->incidentReference ?? '',
        );
    }
}
