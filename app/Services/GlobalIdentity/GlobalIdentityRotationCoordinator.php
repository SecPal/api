<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Contracts\GlobalIdentityRotationAuthority;
use App\Exceptions\GlobalIdentityKeyException;
use App\Models\GlobalIdentityRotation;
use App\Models\GlobalIdentityTwoFactorAuthentication;
use App\Models\GlobalIdentityWorkingKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class GlobalIdentityRotationCoordinator
{
    private const LOCK = [0x53454350, 0x4749524F];

    public function __construct(
        private GlobalIdentityWorkingKeyRegistry $keys,
        private GlobalEmailRepository $email,
        private GlobalIdentityDeliveryIncident $incidents,
        private GlobalIdentityKeyReferences $references,
        private GlobalIdentityOperationService $operations,
        private ?GlobalIdentityRotationAuthority $authority = null,
    ) {}

    /** @return array{phase: string, processed: int} */
    public function execute(string $operationId, string $action, int $limit): array
    {
        if (! GlobalIdentityOperationContext::isCanonicalId($operationId)
            || ! in_array($action, ['start', 'advance', 'complete', 'pause', 'resume', 'retire', 'status'], true)
            || $limit < 1 || $limit > 500) {
            throw new GlobalIdentityKeyException('Invalid rotation request.');
        }
        if ($this->authority === null) {
            $this->reject($operationId, 'authority_unavailable');
        }
        try {
            // External evidence is validated before any database locks are held.
            $plan = $this->authority->approvedPlan($operationId, $action);
        } catch (\Throwable) {
            $this->reject($operationId, 'authority_rejected');
        }
        if ($plan->operationId !== $operationId) {
            $this->reject($operationId, 'scope_mismatch');
        }

        try {
            return DB::transaction(function () use ($plan, $action, $limit): array {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
                $checkpoint = GlobalIdentityRotation::query()->lockForUpdate()->find($plan->operationId);
                if ($checkpoint === null) {
                    if ($action !== 'start') {
                        $this->reject($plan->operationId, 'checkpoint_missing');
                    }
                    $checkpoint = $this->start($plan);
                }
                if ($checkpoint->scope != $plan->scope()) {
                    $this->reject($plan->operationId, 'scope_mismatch');
                }
                if ($action === 'advance') {
                    $this->advance($checkpoint, $plan, $limit);
                } elseif ($action === 'complete') {
                    $this->complete($checkpoint, $plan);
                } elseif ($action === 'pause' || $action === 'resume') {
                    $this->setPaused($checkpoint, $plan, $action === 'pause');
                } elseif ($action === 'retire') {
                    $this->retire($checkpoint, $plan);
                }

                return ['phase' => $checkpoint->phase, 'processed' => $checkpoint->processed];
            });
        } catch (QueryException|\JsonException) {
            $this->reject($operationId, 'persistence_failed');
        }
    }

    private function start(GlobalIdentityRotationPlan $plan): GlobalIdentityRotation
    {
        if ($this->hasRunningRotation()) {
            $this->reject($plan->operationId, 'another_rotation_active');
        }
        if (in_array($plan->kind, [GlobalIdentityRotationKind::Root, GlobalIdentityRotationKind::Working], true)) {
            foreach (GlobalIdentityWorkingKeyPurpose::cases() as $purpose) {
                $this->keys->withActiveKey($purpose, static fn (): null => null);
            }
        }
        if ($plan->kind === GlobalIdentityRotationKind::Root) {
            if (! GlobalIdentityWorkingKey::query()->where('root_id', $plan->from->id)
                ->where('root_version', $plan->from->version)->exists()) {
                $this->reject($plan->operationId, 'source_root_missing');
            }
            $phase = 'rewrap';
        } elseif (in_array($plan->kind, [GlobalIdentityRotationKind::DeliveryCompromise, GlobalIdentityRotationKind::DeliveryLoss], true)) {
            $this->incidents->block($plan);
            $phase = 'revoke';
        } else {
            $purpose = $plan->purpose ?? $this->reject($plan->operationId, 'scope_mismatch');
            if ($this->keys->activeReference($purpose) != $plan->from) {
                $this->reject($plan->operationId, 'source_key_changed');
            }
            $target = $this->keys->stage($purpose);
            if ($target->reference != $plan->to) {
                $this->reject($plan->operationId, 'target_key_changed');
            }
            $phase = match ($purpose) {
                GlobalIdentityWorkingKeyPurpose::UserEmailLookup => 'backfill',
                GlobalIdentityWorkingKeyPurpose::EmailEncryption,
                GlobalIdentityWorkingKeyPurpose::MfaCredentials,
                GlobalIdentityWorkingKeyPurpose::PendingEmailChangeEncryption,
                GlobalIdentityWorkingKeyPurpose::InvitationAddressEncryption => 'rewrite',
                default => 'drain',
            };
            if ($phase === 'backfill') {
                $this->keys->activateLookupRotation($target);
            } elseif ($purpose !== GlobalIdentityWorkingKeyPurpose::UserEmailReservation) {
                $this->keys->activate($target);
            }
        }

        $checkpoint = GlobalIdentityRotation::query()->create([
            'id' => $plan->operationId,
            'scope' => $plan->scope(),
            'phase' => $phase,
            'processed' => 0,
        ]);
        $this->audit($checkpoint);

        return $checkpoint;
    }

    private function advance(GlobalIdentityRotation $checkpoint, GlobalIdentityRotationPlan $plan, int $limit): void
    {
        $this->requireActiveTarget($plan, $checkpoint->phase);
        switch ($checkpoint->phase) {
            case 'rewrap':
                $changed = $this->keys->rewrapRootBatch($plan->from, $plan->to, $limit);
                $this->checkpoint($checkpoint, $changed < $limit ? 'verify' : 'rewrap', $changed);
                break;
            case 'rewrite':
            case 'backfill':
            case 'promote':
                $this->advanceRecords($checkpoint, $plan, $limit);
                break;
            case 'cleanup':
                $target = new GlobalIdentityWorkingKeyContext(GlobalIdentityWorkingKeyPurpose::UserEmailLookup, $plan->to);
                if ($this->email->completeLookupRotation($target, $limit)) {
                    $this->checkpoint($checkpoint, 'complete', 0);
                }
                break;
            case 'revoke':
                $revoked = $this->incidents->revokeAffected($plan, $limit);
                $this->checkpoint($checkpoint, $revoked < $limit ? 'activate' : 'revoke', $revoked);
                break;
            case 'activate':
                $this->incidents->activateReplacementKey($plan);
                $this->checkpoint($checkpoint, 'replace', 0);
                break;
            case 'replace':
                $replaced = $this->incidents->replaceApproved($plan, $limit);
                $this->checkpoint($checkpoint, $replaced < $limit ? 'verify' : 'replace', $replaced);
                break;
            case 'drain':
                $context = $this->sourceContext($plan);
                $ready = $context->purpose === GlobalIdentityWorkingKeyPurpose::UserEmailReservation
                    ? $this->keys->activateDrainedReservationKey(
                        new GlobalIdentityWorkingKeyContext($context->purpose, $plan->to),
                        fn (): bool => $this->references->hasLiveReference($context),
                    )
                    : ! $this->references->hasLiveReference($context);
                if ($ready) {
                    $this->checkpoint($checkpoint, 'verify', 0);
                }
                break;
            case 'paused':
                $this->reject($plan->operationId, 'rotation_paused');
        }
    }

    private function advanceRecords(GlobalIdentityRotation $checkpoint, GlobalIdentityRotationPlan $plan, int $limit): void
    {
        $table = match ($plan->purpose) {
            GlobalIdentityWorkingKeyPurpose::MfaCredentials => 'two_factor_authentications',
            GlobalIdentityWorkingKeyPurpose::PendingEmailChangeEncryption,
            GlobalIdentityWorkingKeyPurpose::InvitationAddressEncryption => 'global_identity_operations',
            default => 'users',
        };
        $query = DB::table($table)->orderBy('id')->limit($limit);
        if ($checkpoint->cursor !== null) {
            $query->where('id', '>', $checkpoint->cursor);
        }
        $ids = $query->pluck('id')->all();
        $cursor = $checkpoint->cursor;
        foreach ($ids as $id) {
            if (! is_int($id) && ! is_string($id)) {
                $this->reject($checkpoint->id, 'invalid_checkpoint_subject');
            }
            $cursor = (string) $id;
            $this->rewriteRecord($checkpoint, $plan, $cursor);
        }
        if (count($ids) < $limit) {
            $this->checkpoint($checkpoint, $checkpoint->phase === 'backfill' ? 'promote' : 'verify', count($ids));
        } else {
            $this->checkpoint($checkpoint, $checkpoint->phase, count($ids), $cursor);
        }
    }

    private function rewriteRecord(GlobalIdentityRotation $checkpoint, GlobalIdentityRotationPlan $plan, string $id): void
    {
        $purpose = $plan->purpose;
        if ($purpose === GlobalIdentityWorkingKeyPurpose::MfaCredentials) {
            GlobalIdentityTwoFactorAuthentication::query()->lockForUpdate()->find($id)?->reencryptMaterial();

            return;
        }
        if (! GlobalIdentityOperationContext::isCanonicalId($id)) {
            $this->reject($checkpoint->id, 'invalid_checkpoint_subject');
        }
        match ($purpose) {
            GlobalIdentityWorkingKeyPurpose::UserEmailLookup => $this->email->rotateIndex($id, promote: $checkpoint->phase === 'promote'),
            GlobalIdentityWorkingKeyPurpose::PendingEmailChangeEncryption,
            GlobalIdentityWorkingKeyPurpose::InvitationAddressEncryption => $this->operations->rotateRecipient($id, $purpose),
            GlobalIdentityWorkingKeyPurpose::EmailEncryption => $this->email->reencrypt($id),
            default => $this->reject($plan->operationId, 'invalid_rewrite_scope'),
        };
    }

    private function complete(GlobalIdentityRotation $checkpoint, GlobalIdentityRotationPlan $plan): void
    {
        if (in_array($checkpoint->phase, ['complete', 'cleanup'], true)) {
            return;
        }
        if ($checkpoint->phase !== 'verify') {
            $this->reject($plan->operationId, 'rotation_incomplete');
        }
        $this->requireActiveTarget($plan, $checkpoint->phase);
        if ($plan->kind === GlobalIdentityRotationKind::Root
            && GlobalIdentityWorkingKey::query()->where('root_id', $plan->from->id)->where('root_version', $plan->from->version)->exists()) {
            $this->reject($plan->operationId, 'root_references_remain');
        }
        if ($plan->purpose !== null && $plan->purpose !== GlobalIdentityWorkingKeyPurpose::UserEmailLookup
            && $plan->kind !== GlobalIdentityRotationKind::DeliveryLoss
            && $this->references->hasLiveReference($this->sourceContext($plan))) {
            $this->reject($plan->operationId, 'live_references');
        }
        $this->checkpoint($checkpoint, $plan->purpose === GlobalIdentityWorkingKeyPurpose::UserEmailLookup ? 'cleanup' : 'complete', 0);
    }

    private function retire(GlobalIdentityRotation $checkpoint, GlobalIdentityRotationPlan $plan): void
    {
        if ($checkpoint->phase !== 'complete') {
            $this->reject($plan->operationId, 'rotation_incomplete');
        }
        $source = $this->sourceContext($plan);
        $this->keys->retire($source, fn (): bool => $this->references->hasLiveReference($source));
    }

    private function setPaused(GlobalIdentityRotation $checkpoint, GlobalIdentityRotationPlan $plan, bool $pause): void
    {
        if ($pause && $checkpoint->phase === 'paused') {
            return;
        }
        if ($pause) {
            if ($checkpoint->phase === 'complete'
                || in_array($plan->kind, [GlobalIdentityRotationKind::DeliveryCompromise, GlobalIdentityRotationKind::DeliveryLoss], true)) {
                $this->reject($plan->operationId, 'rotation_cannot_pause');
            }
            $values = ['phase' => 'paused', 'resume_phase' => $checkpoint->phase];
        } else {
            if ($checkpoint->resume_phase === null || $this->hasRunningRotation()) {
                $this->reject($plan->operationId, 'rotation_cannot_resume');
            }
            $this->requireActiveTarget($plan, $checkpoint->resume_phase);
            $values = ['phase' => $checkpoint->resume_phase, 'resume_phase' => null];
        }
        if (! $checkpoint->update($values)) {
            $this->reject($plan->operationId, 'checkpoint_write_failed');
        }
        $this->audit($checkpoint);
    }

    private function hasRunningRotation(): bool
    {
        return GlobalIdentityRotation::query()->whereNotIn('phase', ['complete', 'paused'])->exists();
    }

    private function requireActiveTarget(GlobalIdentityRotationPlan $plan, string $phase): void
    {
        $purpose = $plan->purpose;
        if ($purpose === null || in_array($phase, ['revoke', 'activate', 'paused', 'complete'], true)) {
            return;
        }
        $expected = $phase === 'drain' && $purpose === GlobalIdentityWorkingKeyPurpose::UserEmailReservation ? $plan->from : $plan->to;
        if ($this->keys->activeReference($purpose) != $expected) {
            $this->reject($plan->operationId, 'target_key_changed');
        }
    }

    private function sourceContext(GlobalIdentityRotationPlan $plan): GlobalIdentityWorkingKeyContext
    {
        $purpose = $plan->purpose ?? $this->reject($plan->operationId, 'root_retirement_external');

        return new GlobalIdentityWorkingKeyContext($purpose, $plan->from);
    }

    private function checkpoint(GlobalIdentityRotation $record, string $phase, int $processed, ?string $cursor = null): void
    {
        if (! $record->update([
            'phase' => $phase,
            'processed' => $record->processed + $processed,
            'cursor' => $cursor,
        ])) {
            $this->reject($record->id, 'checkpoint_write_failed');
        }
        $this->audit($record);
    }

    private function audit(GlobalIdentityRotation $record): void
    {
        $data = ['operation_id' => $record->id, 'phase' => $record->phase, 'processed' => $record->processed];
        DB::afterCommit(static fn () => Log::info('Global identity rotation checkpoint.', $data));
    }

    private function reject(string $id, string $reason): never
    {
        Log::warning('Global identity rotation rejected.', ['operation_id' => $id, 'reason' => $reason]);

        throw new GlobalIdentityKeyException('Global identity rotation unavailable. '.$reason);
    }
}
