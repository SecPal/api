<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;

class LegalHoldRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): LegalHold
    {
        return LegalHold::query()->create($attributes);
    }

    public function inspect(int $tenantId, string $legalHoldId): LegalHold
    {
        return LegalHold::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($legalHoldId)
            ->with('attachments')
            ->firstOrFail();
    }

    public function lock(int $tenantId, string $legalHoldId): LegalHold
    {
        return LegalHold::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($legalHoldId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function findActivity(int $tenantId, int $activityId): Activity
    {
        return Activity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($activityId)
            ->firstOrFail();
    }

    public function lockAttachment(
        int $tenantId,
        string $legalHoldId,
        string $attachmentId,
    ): LegalHoldActivityAttachment {
        return LegalHoldActivityAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_hold_id', $legalHoldId)
            ->whereKey($attachmentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function attach(array $attributes): LegalHoldActivityAttachment
    {
        return LegalHoldActivityAttachment::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function updateAttachment(
        LegalHoldActivityAttachment $attachment,
        array $attributes,
    ): LegalHoldActivityAttachment {
        $attachment->fill($attributes)->save();

        return $attachment;
    }

    /** @param array<string, mixed> $attributes */
    public function updateHold(LegalHold $legalHold, array $attributes): LegalHold
    {
        $legalHold->fill($attributes)->save();

        return $legalHold;
    }
}
