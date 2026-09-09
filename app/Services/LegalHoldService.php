<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\LegalHoldStatus;
use App\Exceptions\DuplicateActiveLegalHoldAttachmentException;
use App\Exceptions\LegalHoldAttachmentAlreadyDetachedException;
use App\Exceptions\LegalHoldCaseReferenceConflictException;
use App\Exceptions\LegalHoldNotActiveException;
use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\User;
use App\Repositories\LegalHoldRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class LegalHoldService
{
    public function __construct(
        private LegalHoldRepository $legalHolds,
        private PermissionRegistrar $permissions,
    ) {}

    public function create(User $actor, string $caseReference, string $justification): LegalHold
    {
        $tenantId = $this->authorizeActor($actor);
        $caseReference = $this->bounded($caseReference, 64, 'case reference');
        $justification = $this->bounded($justification, 2000, 'justification');

        try {
            return DB::transaction(fn (): LegalHold => $this->legalHolds->create([
                'tenant_id' => $tenantId,
                'case_reference' => $caseReference,
                'status' => LegalHoldStatus::Active,
                'justification' => $justification,
                'created_by_user_id' => $actor->id,
                'created_by_identity_id' => $actor->id,
            ]));
        } catch (QueryException $exception) {
            throw LegalHoldCaseReferenceConflictException::fromQueryException($exception) ?? $exception;
        }
    }

    public function inspect(User $actor, string $legalHoldId): LegalHold
    {
        $legalHold = $this->legalHolds->inspect($this->authorizeActor($actor), $legalHoldId);

        foreach ($legalHold->attachments as $attachment) {
            $this->authorizeActivity($actor, $attachment->activity);
        }

        return $legalHold;
    }

    public function attach(User $actor, string $legalHoldId, int $activityId): LegalHoldActivityAttachment
    {
        $tenantId = $this->authorizeActor($actor);

        try {
            return DB::transaction(function () use ($actor, $tenantId, $legalHoldId, $activityId): LegalHoldActivityAttachment {
                $activity = $this->legalHolds->lockActivity($tenantId, $activityId);
                $legalHold = $this->legalHolds->lock($tenantId, $legalHoldId);
                $this->requireActive($legalHold);
                Gate::forUser($actor)->authorize('view', $activity);
                $attachedAt = now();

                return $this->legalHolds->attach([
                    'tenant_id' => $tenantId,
                    'legal_hold_id' => $legalHold->id,
                    'activity_id' => $activity->id,
                    'activity_identity_id' => $activity->id,
                    'attached_by_user_id' => $actor->id,
                    'attached_by_identity_id' => $actor->id,
                    'attached_at' => $attachedAt,
                ]);
            });
        } catch (QueryException $exception) {
            throw DuplicateActiveLegalHoldAttachmentException::fromQueryException($exception) ?? $exception;
        }
    }

    public function detach(
        User $actor,
        string $legalHoldId,
        string $attachmentId,
        string $justification,
    ): LegalHoldActivityAttachment {
        $tenantId = $this->authorizeActor($actor);
        $justification = $this->bounded($justification, 2000, 'detachment justification');

        return DB::transaction(function () use ($actor, $tenantId, $legalHoldId, $attachmentId, $justification): LegalHoldActivityAttachment {
            $legalHold = $this->legalHolds->lock($tenantId, $legalHoldId);
            $this->requireActive($legalHold);
            $attachment = $this->legalHolds->lockAttachment($tenantId, $legalHold->id, $attachmentId);
            $this->authorizeActivity($actor, $attachment->activity);

            if ($attachment->detached_at !== null) {
                throw new LegalHoldAttachmentAlreadyDetachedException;
            }

            $detachedAt = now();

            return $this->legalHolds->updateAttachment($attachment, [
                'detached_at' => $detachedAt,
                'detached_by_user_id' => $actor->id,
                'detached_by_identity_id' => $actor->id,
                'detachment_justification' => $justification,
            ]);
        });
    }

    public function release(User $actor, string $legalHoldId, string $justification): LegalHold
    {
        $tenantId = $this->authorizeActor($actor);
        $justification = $this->bounded($justification, 2000, 'release justification');

        return DB::transaction(function () use ($actor, $tenantId, $legalHoldId, $justification): LegalHold {
            $legalHold = $this->legalHolds->lock($tenantId, $legalHoldId);
            $this->requireActive($legalHold);
            $releasedAt = now();

            return $this->legalHolds->updateHold($legalHold, [
                'status' => LegalHoldStatus::Released,
                'released_at' => $releasedAt,
                'released_by_user_id' => $actor->id,
                'released_by_identity_id' => $actor->id,
                'release_justification' => $justification,
            ]);
        });
    }

    /** @throws AuthorizationException */
    private function authorizeActor(User $actor): int
    {
        $tenantId = $this->permissions->getPermissionsTeamId();

        if (! is_int($tenantId)
            || $actor->tenant_id === null
            || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        Gate::forUser($actor)->authorize('viewAny', Activity::class);

        return $tenantId;
    }

    private function requireActive(LegalHold $legalHold): void
    {
        if ($legalHold->status !== LegalHoldStatus::Active) {
            throw new LegalHoldNotActiveException;
        }
    }

    private function authorizeActivity(User $actor, ?Activity $activity): void
    {
        if ($activity !== null) {
            Gate::forUser($actor)->authorize('view', $activity);
        }
    }

    private function bounded(string $value, int $maximumLength, string $name): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException("The {$name} must contain between 1 and {$maximumLength} characters.");
        }

        return $value;
    }
}
