<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Auditing\LegalHoldAuditContext;
use App\Enums\LegalHoldAuditOperation;
use App\Enums\LegalHoldAuditOutcome;
use App\Enums\LegalHoldAuditReasonCategory;
use App\Enums\LegalHoldStatus;
use App\Exceptions\DuplicateActiveLegalHoldAttachmentException;
use App\Exceptions\LegalHoldAttachmentAlreadyDetachedException;
use App\Exceptions\LegalHoldAuditFailureException;
use App\Exceptions\LegalHoldCaseReferenceConflictException;
use App\Exceptions\LegalHoldNotActiveException;
use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\User;
use App\Repositories\LegalHoldRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final readonly class LegalHoldService
{
    public function __construct(
        private LegalHoldRepository $legalHolds,
        private PermissionRegistrar $permissions,
        private LegalHoldAuditRecorder $audits,
    ) {}

    public function create(User $actor, string $caseReference, string $justification): LegalHold
    {
        $tenantId = $this->authorizeActor($actor);
        $context = new LegalHoldAuditContext($actor, $tenantId, LegalHoldAuditOperation::Create);

        try {
            $caseReference = $this->bounded($caseReference, 64, 'case reference');
            $context->useRequestedCaseReference($caseReference);
            $justification = $this->bounded($justification, 2000, 'justification');

            return DB::transaction(function () use ($actor, $tenantId, $caseReference, $justification, $context): LegalHold {
                $legalHold = $this->legalHolds->create([
                    'tenant_id' => $tenantId,
                    'case_reference' => $caseReference,
                    'status' => LegalHoldStatus::Active,
                    'justification' => $justification,
                    'created_by_user_id' => $actor->id,
                    'created_by_identity_id' => $actor->id,
                ]);
                $context->resolveHold($legalHold);
                $this->recordSuccess($context);

                return $legalHold;
            });
        } catch (Throwable $exception) {
            $this->handleFailure($context, $this->mapCreateFailure($exception));
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
        $context = new LegalHoldAuditContext($actor, $tenantId, LegalHoldAuditOperation::Attach);

        try {
            return DB::transaction(function () use ($actor, $tenantId, $legalHoldId, $activityId, $context): LegalHoldActivityAttachment {
                $activity = $this->legalHolds->lockActivity($tenantId, $activityId);
                $context->resolveActivity($activity);
                $legalHold = $this->legalHolds->lock($tenantId, $legalHoldId);
                $context->resolveHold($legalHold);
                $this->requireActive($legalHold);
                Gate::forUser($actor)->authorize('view', $activity);
                $attachedAt = now();

                $attachment = $this->legalHolds->attach([
                    'tenant_id' => $tenantId,
                    'legal_hold_id' => $legalHold->id,
                    'activity_id' => $activity->id,
                    'activity_identity_id' => $activity->id,
                    'attached_by_user_id' => $actor->id,
                    'attached_by_identity_id' => $actor->id,
                    'attached_at' => $attachedAt,
                ]);
                $context->resolveAttachment($attachment);
                $this->recordSuccess($context);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->handleFailure($context, $this->mapAttachFailure($exception));
        }
    }

    public function detach(
        User $actor,
        string $legalHoldId,
        string $attachmentId,
        string $justification,
    ): LegalHoldActivityAttachment {
        $tenantId = $this->authorizeActor($actor);
        $context = new LegalHoldAuditContext($actor, $tenantId, LegalHoldAuditOperation::Detach);

        try {
            $justification = $this->bounded($justification, 2000, 'detachment justification');

            return DB::transaction(function () use ($actor, $tenantId, $legalHoldId, $attachmentId, $justification, $context): LegalHoldActivityAttachment {
                $legalHold = $this->legalHolds->lock($tenantId, $legalHoldId);
                $context->resolveHold($legalHold);
                $this->requireActive($legalHold);
                $attachment = $this->legalHolds->lockAttachment($tenantId, $legalHold->id, $attachmentId);
                $context->resolveAttachment($attachment);
                $this->authorizeActivity($actor, $attachment->activity);

                if ($attachment->detached_at !== null) {
                    throw new LegalHoldAttachmentAlreadyDetachedException;
                }

                $detachedAt = now();
                $attachment = $this->legalHolds->updateAttachment($attachment, [
                    'detached_at' => $detachedAt,
                    'detached_by_user_id' => $actor->id,
                    'detached_by_identity_id' => $actor->id,
                    'detachment_justification' => $justification,
                ]);
                $this->recordSuccess($context);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->handleFailure($context, $exception);
        }
    }

    public function release(User $actor, string $legalHoldId, string $justification): LegalHold
    {
        $tenantId = $this->authorizeActor($actor);
        $context = new LegalHoldAuditContext($actor, $tenantId, LegalHoldAuditOperation::Release);

        try {
            $justification = $this->bounded($justification, 2000, 'release justification');

            return DB::transaction(function () use ($actor, $tenantId, $legalHoldId, $justification, $context): LegalHold {
                $legalHold = $this->legalHolds->lock($tenantId, $legalHoldId);
                $context->resolveHold($legalHold);
                $this->requireActive($legalHold);
                $releasedAt = now();

                $legalHold = $this->legalHolds->updateHold($legalHold, [
                    'status' => LegalHoldStatus::Released,
                    'released_at' => $releasedAt,
                    'released_by_user_id' => $actor->id,
                    'released_by_identity_id' => $actor->id,
                    'release_justification' => $justification,
                ]);
                $this->recordSuccess($context);

                return $legalHold;
            });
        } catch (Throwable $exception) {
            $this->handleFailure($context, $exception);
        }
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
            throw new InvalidArgumentException("The {$name} must contain between 1 and {$maximumLength} characters.");
        }

        return $value;
    }

    private function recordSuccess(LegalHoldAuditContext $context): void
    {
        try {
            $this->audits->record(
                $context,
                LegalHoldAuditOutcome::Succeeded,
                LegalHoldAuditReasonCategory::Completed,
            );
        } catch (Throwable $exception) {
            throw LegalHoldAuditFailureException::forSuccessfulMutation(
                $context->operation,
                $exception,
            );
        }
    }

    private function handleFailure(LegalHoldAuditContext $context, Throwable $failure): never
    {
        if ($failure instanceof LegalHoldAuditFailureException
            || $failure instanceof AuthorizationException) {
            throw $failure;
        }

        $reasonCategory = $this->failureReason($context, $failure);

        if ($reasonCategory === null) {
            throw $failure;
        }

        try {
            DB::transaction(fn (): Activity => $this->audits->record(
                $context,
                LegalHoldAuditOutcome::Failed,
                $reasonCategory,
            ));
        } catch (Throwable $auditFailure) {
            throw LegalHoldAuditFailureException::afterFailedMutation(
                $context->operation,
                $failure,
                $auditFailure,
            );
        }

        throw $failure;
    }

    private function failureReason(
        LegalHoldAuditContext $context,
        Throwable $failure,
    ): ?LegalHoldAuditReasonCategory {
        return match (true) {
            $failure instanceof InvalidArgumentException => LegalHoldAuditReasonCategory::InvalidInput,
            $failure instanceof LegalHoldCaseReferenceConflictException => LegalHoldAuditReasonCategory::CaseReferenceConflict,
            $failure instanceof LegalHoldNotActiveException => LegalHoldAuditReasonCategory::HoldNotActive,
            $failure instanceof DuplicateActiveLegalHoldAttachmentException => LegalHoldAuditReasonCategory::DuplicateActiveAttachment,
            $failure instanceof LegalHoldAttachmentAlreadyDetachedException => LegalHoldAuditReasonCategory::AttachmentAlreadyDetached,
            $failure instanceof ModelNotFoundException => LegalHoldAuditReasonCategory::TargetUnavailable,
            $context->operation === LegalHoldAuditOperation::Create
                || $context->hasResolvedHold() => LegalHoldAuditReasonCategory::PersistenceFailure,
            default => null,
        };
    }

    private function mapCreateFailure(Throwable $failure): Throwable
    {
        if (! $failure instanceof QueryException) {
            return $failure;
        }

        return LegalHoldCaseReferenceConflictException::fromQueryException($failure) ?? $failure;
    }

    private function mapAttachFailure(Throwable $failure): Throwable
    {
        if (! $failure instanceof QueryException) {
            return $failure;
        }

        return DuplicateActiveLegalHoldAttachmentException::fromQueryException($failure) ?? $failure;
    }
}
