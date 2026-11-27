<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;

final readonly class GlobalIdentityRotationPlan
{
    /** @var list<string> */
    public array $replacementOperations;

    /** @param list<string> $replacementOperations */
    public function __construct(
        public string $operationId,
        public GlobalIdentityRotationKind $kind,
        public GlobalIdentityKeyReference $from,
        public GlobalIdentityKeyReference $to,
        public ?GlobalIdentityWorkingKeyPurpose $purpose = null,
        array $replacementOperations = [],
    ) {
        $incident = in_array($kind, [GlobalIdentityRotationKind::DeliveryCompromise, GlobalIdentityRotationKind::DeliveryLoss], true);
        if (! GlobalIdentityOperationContext::isCanonicalId($operationId) || $from == $to
            || ($kind === GlobalIdentityRotationKind::Root) !== ($purpose === null)
            || ($purpose !== null && (! GlobalIdentityOperationContext::isCanonicalId($from->id)
                || $to->id !== $from->id || $to->version <= $from->version
                || (! $incident && $to->version !== $from->version + 1)))
            || ($incident && $purpose !== GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret)
            || (! $incident && $replacementOperations !== [])
            || ! array_is_list($replacementOperations)
            || count(array_unique($replacementOperations)) !== count($replacementOperations)) {
            throw new GlobalIdentityKeyException('Invalid global identity rotation plan.');
        }
        foreach ($replacementOperations as $id) {
            if (! GlobalIdentityOperationContext::isCanonicalId($id)) {
                throw new GlobalIdentityKeyException('Invalid global identity rotation plan.');
            }
        }
        sort($replacementOperations, SORT_STRING);
        $this->replacementOperations = $replacementOperations;
    }

    /** @return array<string, int|string|list<string>|null> */
    public function scope(): array
    {
        return [
            'kind' => $this->kind->value,
            'purpose' => $this->purpose?->value,
            'from_id' => $this->from->id,
            'from_version' => $this->from->version,
            'to_id' => $this->to->id,
            'to_version' => $this->to->version,
            'replacements' => $this->replacementOperations,
        ];
    }
}
