<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Auditing;

use App\Enums\LegalHoldAuditOperation;
use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\User;
use LogicException;

final class LegalHoldAuditContext
{
    private ?string $caseReference = null;

    private ?string $legalHoldId = null;

    private ?string $attachmentId = null;

    private ?int $activityIdentityId = null;

    public function __construct(
        public readonly User $actor,
        public readonly int $tenantId,
        public readonly LegalHoldAuditOperation $operation,
    ) {
        if ($tenantId < 1 || $actor->tenant_id !== $tenantId) {
            throw new LogicException('Legal Hold audit context requires an authoritative tenant actor.');
        }
    }

    public function useRequestedCaseReference(string $caseReference): void
    {
        if ($caseReference === '' || mb_strlen($caseReference) > 64) {
            throw new LogicException('Legal Hold audit case reference must be bounded.');
        }

        $this->caseReference = $caseReference;
    }

    public function resolveHold(LegalHold $legalHold): void
    {
        $this->requireTenant($legalHold->tenant_id);
        $this->legalHoldId = $legalHold->id;
        $this->caseReference = $legalHold->case_reference;
    }

    public function resolveActivity(Activity $activity): void
    {
        $this->requireTenant($activity->tenant_id);
        $this->activityIdentityId = $activity->id;
    }

    public function resolveAttachment(LegalHoldActivityAttachment $attachment): void
    {
        $this->requireTenant($attachment->tenant_id);

        if ($this->legalHoldId === null || $attachment->legal_hold_id !== $this->legalHoldId) {
            throw new LogicException('Legal Hold audit attachment must belong to the resolved hold.');
        }

        $this->attachmentId = $attachment->id;
        $this->activityIdentityId = $attachment->activity_identity_id;
    }

    public function caseReference(): ?string
    {
        return $this->caseReference;
    }

    public function legalHoldId(): ?string
    {
        return $this->legalHoldId;
    }

    public function attachmentId(): ?string
    {
        return $this->attachmentId;
    }

    public function activityIdentityId(): ?int
    {
        return $this->activityIdentityId;
    }

    public function hasResolvedHold(): bool
    {
        return $this->legalHoldId !== null;
    }

    private function requireTenant(int $tenantId): void
    {
        if ($tenantId !== $this->tenantId) {
            throw new LogicException('Legal Hold audit context cannot cross tenant boundaries.');
        }
    }
}
