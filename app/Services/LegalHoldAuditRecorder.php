<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Auditing\LegalHoldAuditContext;
use App\Enums\LegalHoldAuditOutcome;
use App\Enums\LegalHoldAuditReasonCategory;
use App\Models\Activity;
use RuntimeException;

class LegalHoldAuditRecorder
{
    private const int SCHEMA_VERSION = 1;

    public function record(
        LegalHoldAuditContext $context,
        LegalHoldAuditOutcome $outcome,
        LegalHoldAuditReasonCategory $reasonCategory,
    ): Activity {
        $audit = activity('security')
            ->causedBy($context->actor)
            ->useLog('security')
            ->event($this->eventName($context, $outcome))
            ->withProperties($this->metadata($context, $outcome, $reasonCategory))
            ->tap(function ($activity) use ($context): void {
                /** @var Activity $activity */
                $activity->tenant_id = $context->tenantId;
                $activity->suppressRequestOrganizationalUnitCapture();
            })
            ->log($this->description($outcome));

        if (! $audit instanceof Activity) {
            throw new RuntimeException('Required Legal Hold audit activity was not persisted.');
        }

        return $audit->refresh();
    }

    /**
     * @return array{
     *     schema_version: int,
     *     operation: string,
     *     outcome: string,
     *     case_reference: string|null,
     *     reason_category: string,
     *     legal_hold_id?: string,
     *     attachment_id?: string,
     *     activity_identity_id?: int
     * }
     */
    private function metadata(
        LegalHoldAuditContext $context,
        LegalHoldAuditOutcome $outcome,
        LegalHoldAuditReasonCategory $reasonCategory,
    ): array {
        $metadata = [
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => $context->operation->value,
            'outcome' => $outcome->value,
            'case_reference' => $context->caseReference(),
            'reason_category' => $reasonCategory->value,
        ];

        if ($context->legalHoldId() !== null) {
            $metadata['legal_hold_id'] = $context->legalHoldId();
        }

        if ($context->attachmentId() !== null) {
            $metadata['attachment_id'] = $context->attachmentId();
        }

        if ($context->activityIdentityId() !== null) {
            $metadata['activity_identity_id'] = $context->activityIdentityId();
        }

        return $metadata;
    }

    private function eventName(
        LegalHoldAuditContext $context,
        LegalHoldAuditOutcome $outcome,
    ): string {
        return "legal_hold.{$context->operation->value}.{$outcome->value}";
    }

    private function description(LegalHoldAuditOutcome $outcome): string
    {
        return match ($outcome) {
            LegalHoldAuditOutcome::Succeeded => 'Legal Hold lifecycle mutation succeeded',
            LegalHoldAuditOutcome::Failed => 'Legal Hold lifecycle mutation failed',
        };
    }
}
