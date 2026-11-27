<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use App\Jobs\DeliverGlobalIdentityOperation;
use App\Models\GlobalIdentityOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final readonly class GlobalIdentityDeliveryIncident
{
    private const REISSUES = 'global_identity_rotation_reissues';

    public function __construct(
        private GlobalIdentityWorkingKeyRegistry $keys,
        private GlobalIdentityOperationService $operations,
        private GlobalIdentityOperationSubject $subject,
        private GlobalEmailRepository $email,
    ) {}

    public function block(GlobalIdentityRotationPlan $plan): void
    {
        $this->keys->blockDeliveryVersion($plan->from, $this->reason($plan), $plan->to);
    }

    public function revokeAffected(GlobalIdentityRotationPlan $plan, int $limit): int
    {
        $reason = $this->reason($plan);
        $rows = $this->affected($plan)->orderBy('id')->limit($limit)->get(['id']);
        foreach ($rows as $row) {
            $id = $row->id;
            $this->operations->revokeForIncident(
                $id,
                $plan->from,
                $reason,
                in_array($id, $plan->replacementOperations, true)
                    ? fn (GlobalIdentityOperation $operation) => $this->preserveRecipient($plan, $operation)
                    : null,
            );
        }

        return $rows->count();
    }

    public function activateReplacementKey(GlobalIdentityRotationPlan $plan): void
    {
        if ($this->keys->activeReference(GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret) == $plan->to) {
            if ($this->affected($plan)->exists()) {
                throw new GlobalIdentityKeyException('Delivery incident revocation incomplete.');
            }

            return;
        }
        $target = $this->keys->stageBlockedDeliveryReplacement($plan->from);
        if ($target->reference != $plan->to) {
            throw new GlobalIdentityKeyException('Delivery incident target unavailable.');
        }
        $this->keys->activateDeliveryReplacement($target, fn (): bool => ! $this->affected($plan)->exists());
    }

    public function replaceApproved(GlobalIdentityRotationPlan $plan, int $limit): int
    {
        $pending = DB::table(self::REISSUES)->where('rotation_id', $plan->operationId);
        $rows = (clone $pending)->orderBy('operation_id')->limit($limit)->lockForUpdate()->get();
        foreach ($rows as $row) {
            $recipient = $row->recipient_enc;
            if (! is_string($row->operation_id) || ($recipient !== null && ! is_string($recipient))) {
                throw new GlobalIdentityKeyException('Invalid delivery incident checkpoint.');
            }
            $source = GlobalIdentityOperation::query()->findOrFail($row->operation_id);
            $replace = function () use ($plan, $source, $recipient): void {
                $current = GlobalIdentityOperation::query()->lockForUpdate()->findOrFail($source->id);
                if ($current->operation_type === GlobalIdentityOperationType::PendingEmailChange) {
                    $this->email->release($current->id);
                }
                if ($this->stillRequired($current)) {
                    $this->reissue($plan, $current, $recipient);
                }
            };
            if ($source->user_id !== null) {
                $this->operations->forAccount($source->user_id, $replace);
            } else {
                $replace();
            }
            (clone $pending)->where('operation_id', $source->id)->delete();
        }

        return $rows->count();
    }

    private function stillRequired(#[SensitiveParameter] GlobalIdentityOperation $source): bool
    {
        if (! $source->expires_at->isFuture()) {
            return false;
        }
        if ($source->operation_type === GlobalIdentityOperationType::Invitation && ! $this->subject instanceof GlobalIdentityInvitationReissue) {
            throw new GlobalIdentityKeyException('Invitation replacement authority unavailable.');
        }
        if (! $this->subject->isCurrent($source->context())) {
            return false;
        }
        if ($source->user_id === null) {
            return true;
        }
        if ($source->operation_type === GlobalIdentityOperationType::EmailVerification
            && User::query()->whereKey($source->user_id)->whereNotNull('email_verified_at')->exists()) {
            return false;
        }

        return ! GlobalIdentityOperation::query()->where('user_id', $source->user_id)
            ->where('email_version', $source->email_version)->where('operation_type', $source->operation_type->value)
            ->where('delivery_state', '!=', GlobalIdentityDeliveryState::Terminal->value)
            ->whereNotNull('token_hash')->where('expires_at', '>', now())->exists();
    }

    /** @return Builder<GlobalIdentityOperation> */
    private function affected(GlobalIdentityRotationPlan $plan): Builder
    {
        return GlobalIdentityOperation::query()
            ->where('delivery_key_id', $plan->from->id)->where('delivery_key_version', $plan->from->version)
            ->when($plan->kind === GlobalIdentityRotationKind::DeliveryLoss,
                fn (Builder $query) => $query->where('delivery_state', '!=', GlobalIdentityDeliveryState::Delivered->value))
            ->where(fn (Builder $query) => $query->where('delivery_state', '!=', GlobalIdentityDeliveryState::Terminal->value)
                ->orWhereNotNull('token_hash')->orWhereNotNull('delivery_token_enc')->orWhereNotNull('recipient_enc'));
    }

    private function reason(GlobalIdentityRotationPlan $plan): GlobalIdentityTerminalReason
    {
        return match ($plan->kind) {
            GlobalIdentityRotationKind::DeliveryCompromise => GlobalIdentityTerminalReason::SecurityRevoked,
            GlobalIdentityRotationKind::DeliveryLoss => GlobalIdentityTerminalReason::KeyUnavailable,
            default => throw new GlobalIdentityKeyException('Invalid delivery incident scope.'),
        };
    }

    private function preserveRecipient(GlobalIdentityRotationPlan $plan, #[SensitiveParameter] GlobalIdentityOperation $source): void
    {
        $envelope = $source->operation_type === GlobalIdentityOperationType::PendingEmailChange ? $source->recipient_enc : null;
        DB::table(self::REISSUES)->insert([
            'rotation_id' => $plan->operationId,
            'operation_id' => $source->id,
            'recipient_enc' => $envelope === null ? null : json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
    }

    private function reissue(
        GlobalIdentityRotationPlan $plan,
        #[SensitiveParameter] GlobalIdentityOperation $source,
        #[SensitiveParameter] ?string $sealedRecipient,
    ): void {
        if ($this->keys->activeReference(GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret) != $plan->to) {
            throw new GlobalIdentityKeyException('Replacement delivery key unavailable.');
        }
        $id = (string) Str::uuid();
        $old = $source->context();
        $next = new GlobalIdentityOperationContext(
            $old->type, $id, $old->userId, $old->emailVersion,
            $old->type === GlobalIdentityOperationType::Invitation ? $id : null, $old->tenantId,
        );
        $recipient = null;
        if ($old->type === GlobalIdentityOperationType::Invitation) {
            if (! $this->subject instanceof GlobalIdentityInvitationReissue) {
                throw new GlobalIdentityKeyException('Invitation replacement authority unavailable.');
            }
            $recipient = $this->subject->authorizeReplacement($old, $next);
        } elseif ($sealedRecipient !== null) {
            $envelope = json_decode($sealedRecipient, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope)) {
                throw new GlobalIdentityKeyException('Invalid replacement recipient envelope.');
            }
            $recipient = $this->operations->openRecipientEnvelope($old, $envelope)->original();
        }
        try {
            if ($old->type === GlobalIdentityOperationType::PendingEmailChange && $recipient !== null) {
                $this->keys->withLookupVersions(fn (GlobalEmailLookupVersions $versions) => $this->email->reserve($id, $recipient, $versions));
            }
            $this->operations->create($next, $source->expires_at, $recipient, $plan->operationId, $source->id);
            DeliverGlobalIdentityOperation::dispatch($id);
        } finally {
            if ($recipient !== null) {
                sodium_memzero($recipient);
            }
        }
    }
}
