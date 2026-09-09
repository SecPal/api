<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LegalHoldRepository
{
    /** @return LengthAwarePaginator<int, LegalHold> */
    public function paginate(int $tenantId, int $page, int $perPage): LengthAwarePaginator
    {
        return LegalHold::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

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

    public function lockActivity(int $tenantId, int $activityId): Activity
    {
        return Activity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($activityId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function activityIsActivelyHeld(int $tenantId, int $activityIdentityId): bool
    {
        $held = DB::scalar(
            'SELECT activity_is_actively_held(?, ?)::int',
            [$tenantId, $activityIdentityId],
        );

        if (! is_int($held) && ! is_string($held)) {
            throw new \RuntimeException('Unable to establish activity Legal Hold status.');
        }

        return (int) $held === 1;
    }

    /**
     * @param  list<int>  $activityIdentityIds
     * @return list<int>
     */
    public function activelyHeldActivityIdentityIds(int $tenantId, array $activityIdentityIds): array
    {
        if ($activityIdentityIds === []) {
            return [];
        }

        $heldActivityIds = Activity::query()
            ->where('tenant_id', $tenantId)
            ->whereIntegerInRaw('id', $activityIdentityIds)
            ->whereRaw('activity_is_actively_held(activity_log.tenant_id, activity_log.id)')
            ->pluck('id')
            ->map(static function (mixed $id): int {
                if (! is_int($id) && ! is_string($id)) {
                    throw new \RuntimeException('Invalid held Activity identity returned by the database.');
                }

                return (int) $id;
            })
            ->all();

        return array_values($heldActivityIds);
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
