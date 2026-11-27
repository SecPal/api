<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityDeliveryKeyUnavailable;
use App\Exceptions\GlobalIdentityKeyException;
use App\Models\GlobalIdentityOperation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;

final readonly class GlobalIdentityOperationService
{
    private const LEASE_SECONDS = 60;

    public function __construct(
        private GlobalIdentityWorkingKeyRegistry $keys,
        private GlobalIdentityOperationCodec $codec,
        private GlobalIdentityOperationSubject $subject,
    ) {}

    public function create(
        GlobalIdentityOperationContext $context,
        CarbonImmutable $expires,
        #[SensitiveParameter] ?string $recipient = null,
    ): void {
        $recipientPurpose = $this->storedRecipientPurpose($context);
        if (! $expires->isFuture() || ($recipientPurpose === null) !== ($recipient === null)) {
            throw new GlobalIdentityKeyException('Invalid global identity operation request.');
        }

        $token = $this->randomValue();
        try {
            $this->keys->withActiveKey(
                GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret,
                function (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $workingKey) use ($context, $expires, $recipient, $recipientPurpose, $token): void {
                    $recipientEnvelope = $recipient === null || $recipientPurpose === null ? null : $this->keys->withActiveKey(
                        $recipientPurpose,
                        fn (#[SensitiveParameter] string $key, GlobalIdentityWorkingKeyContext $reference): array => $this->codec->sealRecipient($context, $recipient, $reference, $key),
                    );
                    $operation = new GlobalIdentityOperation;
                    $this->save($operation, [
                        'id' => $context->id,
                        'operation_type' => $context->type,
                        'user_id' => $context->userId,
                        'email_version' => $context->emailVersion,
                        'invitation_id' => $context->invitationId,
                        'tenant_id' => $context->tenantId,
                        'expires_at' => $expires,
                        'token_hash' => GlobalIdentityOperationCodec::hashToken($token),
                        'delivery_token_enc' => $this->codec->sealToken($context, $token, $workingKey, $key),
                        'recipient_enc' => $recipientEnvelope,
                        'delivery_key_id' => $workingKey->reference->id,
                        'delivery_key_version' => $workingKey->reference->version,
                        'delivery_state' => GlobalIdentityDeliveryState::Pending,
                    ]);
                    $this->audit($operation, 'created');
                },
            );
        } catch (QueryException) {
            throw new GlobalIdentityKeyException('Global identity operation unavailable.');
        } finally {
            sodium_memzero($token);
        }
    }

    public function claim(string $id, string $transportIdentity): ?GlobalIdentityOperation
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $transportIdentity) !== 1) {
            throw new GlobalIdentityKeyException('Invalid global identity transport identity.');
        }

        return $this->locked($id, function (GlobalIdentityOperation $operation) use ($transportIdentity): ?GlobalIdentityOperation {
            if (! $this->active($operation)) {
                return null;
            }

            if ($operation->delivery_state === GlobalIdentityDeliveryState::Claimed && $operation->dispatch_committed_at === null
                && $operation->lease_expires_at?->isFuture() !== true) {
                $this->expireLease($operation);
            }
            if (! in_array($operation->delivery_state, [GlobalIdentityDeliveryState::Pending, GlobalIdentityDeliveryState::Retryable], true)) {
                return null;
            }
            if (! $this->subject->isCurrent($operation->context())) {
                $this->terminalize($operation, GlobalIdentityTerminalReason::Superseded);

                return null;
            }
            $this->save($operation, [
                'delivery_state' => GlobalIdentityDeliveryState::Claimed,
                'delivery_attempt_id' => (string) Str::uuid(),
                'delivery_fence_version' => $operation->delivery_fence_version + 1,
                'delivery_attempt_count' => $operation->delivery_attempt_count + 1,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'transport_idempotency_key' => '\x'.bin2hex(random_bytes(32)),
                'transport_identity' => $transportIdentity,
                'uses_idempotency_key' => false,
                'dispatch_committed_at' => null,
                'last_delivery_error_class' => null,
                'provider_message_reference' => null,
            ]);
            $this->audit($operation, 'claimed');

            return $operation;
        });
    }

    public function commitDispatch(#[SensitiveParameter] GlobalIdentityOperation $claim, bool $usesIdempotencyKey = false): bool
    {
        return $this->locked($claim->id, function (GlobalIdentityOperation $operation) use ($claim, $usesIdempotencyKey): bool {
            if (! $this->matches($operation, $claim) || ! $this->active($operation)
                || $operation->delivery_state !== GlobalIdentityDeliveryState::Claimed
                || ($operation->dispatch_committed_at !== null && $operation->uses_idempotency_key !== $usesIdempotencyKey)) {
                return false;
            }
            if ($operation->lease_expires_at?->isFuture() !== true) {
                $this->expireLease($operation);

                return false;
            }
            if (! $this->subject->isCurrent($operation->context())) {
                $this->terminalize($operation, GlobalIdentityTerminalReason::Superseded);

                return false;
            }
            $this->save($operation, [
                'delivery_state' => GlobalIdentityDeliveryState::DispatchCommitted,
                'dispatch_committed_at' => $operation->dispatch_committed_at ?? now(),
                'uses_idempotency_key' => $usesIdempotencyKey,
            ]);
            $this->audit($operation, 'dispatch_committed');

            return true;
        }) ?? false;
    }

    public function preparationFailed(#[SensitiveParameter] GlobalIdentityOperation $claim, bool $keyUnavailable = false): void
    {
        $this->locked($claim->id, function (GlobalIdentityOperation $operation) use ($claim, $keyUnavailable): void {
            if (! $this->matches($operation, $claim) || $operation->delivery_state !== GlobalIdentityDeliveryState::Claimed
                || ! $this->active($operation)) {
                return;
            }
            if ($operation->dispatch_committed_at !== null) {
                $this->unknown($operation, 'preparation_failed');

                return;
            }
            if ($keyUnavailable) {
                $this->retryable($operation, 'delivery_key_unavailable');

                return;
            }

            $this->terminalize($operation, GlobalIdentityTerminalReason::IntegrityFailure);
        });
    }

    public function recordResult(#[SensitiveParameter] GlobalIdentityOperation $claim, #[SensitiveParameter] GlobalIdentityDeliveryResult $result): void
    {
        $this->locked($claim->id, function (GlobalIdentityOperation $operation) use ($claim, $result): void {
            if ($operation->delivery_state === GlobalIdentityDeliveryState::Terminal) {
                $this->audit($operation, 'late_transport_'.$result->outcome->value);

                return;
            }
            if (! $this->matches($operation, $claim) || ! $this->active($operation)
                || ! in_array($operation->delivery_state, [GlobalIdentityDeliveryState::DispatchCommitted, GlobalIdentityDeliveryState::Unknown], true)) {
                return;
            }

            match ($result->outcome) {
                GlobalIdentityDeliveryOutcome::Accepted => $this->delivered($operation, $result->reference),
                GlobalIdentityDeliveryOutcome::RetryableRejection => $this->retryable($operation),
                GlobalIdentityDeliveryOutcome::TerminalRejection => $this->terminalize($operation, GlobalIdentityTerminalReason::TransportRejected),
                GlobalIdentityDeliveryOutcome::Unknown, GlobalIdentityDeliveryOutcome::Absent => $this->unknown($operation, 'transport_unknown', $result->reference),
            };
        });
    }

    public function forReconciliation(string $id): ?GlobalIdentityOperation
    {
        return $this->locked($id, function (GlobalIdentityOperation $operation): ?GlobalIdentityOperation {
            if ($operation->delivery_state === GlobalIdentityDeliveryState::Terminal) {
                $this->clearTerminalResiduals($operation);

                return null;
            }
            if (! $this->active($operation)) {
                return null;
            }
            if (in_array($operation->delivery_state, [GlobalIdentityDeliveryState::Claimed, GlobalIdentityDeliveryState::DispatchCommitted], true)
                && $operation->lease_expires_at?->isFuture() !== true) {
                $this->expireLease($operation);
            }

            return $operation->delivery_state === GlobalIdentityDeliveryState::Unknown ? $operation : null;
        });
    }

    public function claimReplay(
        #[SensitiveParameter] GlobalIdentityOperation $claim,
        #[SensitiveParameter] GlobalIdentityDeliveryResult $observation,
    ): ?GlobalIdentityOperation {
        if ($observation->outcome !== GlobalIdentityDeliveryOutcome::Absent) {
            return null;
        }

        return $this->locked($claim->id, function (GlobalIdentityOperation $operation) use ($claim): ?GlobalIdentityOperation {
            if (! $this->matches($operation, $claim) || ! $this->active($operation)
                || $operation->delivery_state !== GlobalIdentityDeliveryState::Unknown
                || $operation->dispatch_committed_at === null || ! $operation->uses_idempotency_key) {
                return null;
            }
            if (! $this->subject->isCurrent($operation->context())) {
                $this->terminalize($operation, GlobalIdentityTerminalReason::Superseded);

                return null;
            }
            $this->save($operation, [
                'delivery_state' => GlobalIdentityDeliveryState::Claimed,
                'delivery_fence_version' => $operation->delivery_fence_version + 1,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);
            $this->audit($operation, 'replay_claimed');

            return $operation;
        });
    }

    public function invalidate(string $id, GlobalIdentityTerminalReason $reason): void
    {
        $this->locked($id, function (GlobalIdentityOperation $operation) use ($reason): void {
            if ($operation->delivery_state !== GlobalIdentityDeliveryState::Terminal) {
                $this->terminalize($operation, $reason);
            }
        });
    }

    public function quarantineRestoredOperations(): void
    {
        try {
            GlobalIdentityOperation::query()->where('quarantined', false)->update(['quarantined' => true]);
        } catch (QueryException) {
            throw new GlobalIdentityKeyException('Global identity restore quarantine unavailable.');
        }
    }

    /** @param list<GlobalIdentityTerminalJournalEntry> $entries */
    public function applyRestoreTombstones(array $entries): void
    {
        try {
            DB::transaction(function () use ($entries): void {
                foreach ($entries as $entry) {
                    $operation = GlobalIdentityOperation::query()->whereKey($entry->operationId)
                        ->where('quarantined', true)->lockForUpdate()->first();
                    // Re-encryption can change the key version while preserving operation identity.
                    if ($operation?->delivery_state === GlobalIdentityDeliveryState::Terminal) {
                        $this->clearTerminalResiduals($operation);
                    } elseif ($operation !== null) {
                        $this->terminalize($operation, GlobalIdentityTerminalReason::RestoreTombstone);
                    }
                }
            });
        } catch (QueryException) {
            throw new GlobalIdentityKeyException('Global identity restore replay unavailable.');
        }
    }

    public function consume(string $id, #[SensitiveParameter] string $token): bool
    {
        try {
            $hash = GlobalIdentityOperationCodec::hashToken($token);
        } catch (GlobalIdentityKeyException) {
            return false;
        }

        return $this->locked($id, function (GlobalIdentityOperation $operation) use ($hash): bool {
            if (! $this->active($operation) || $operation->token_hash === null || ! hash_equals($operation->token_hash, $hash)) {
                return false;
            }
            if (! $this->subject->isCurrent($operation->context())) {
                $this->terminalize($operation, GlobalIdentityTerminalReason::Superseded);

                return false;
            }
            $this->terminalize($operation, GlobalIdentityTerminalReason::Consumed);

            return true;
        }) ?? false;
    }

    public function openToken(#[SensitiveParameter] GlobalIdentityOperation $claim): string
    {
        $envelope = $claim->delivery_token_enc ?? throw new GlobalIdentityKeyException('Global identity delivery token unavailable.');
        $reference = $this->codec->tokenReference($envelope);
        if ($reference->reference->id !== $claim->delivery_key_id || $reference->reference->version !== $claim->delivery_key_version) {
            throw new GlobalIdentityKeyException('Invalid global identity delivery reference.');
        }

        return $this->withDeliveryKey(
            $reference,
            fn (#[SensitiveParameter] string $key): string => $this->codec->openToken($claim->context(), $envelope, $key),
        );
    }

    public function openRecipient(#[SensitiveParameter] GlobalIdentityOperation $claim): ?GlobalEmailAddress
    {
        $context = $claim->context();
        if ($this->storedRecipientPurpose($context) === null) {
            return null;
        }
        $envelope = $claim->recipient_enc ?? throw new GlobalIdentityKeyException('Global identity recipient unavailable.');

        return $this->withDeliveryKey(
            $this->codec->recipientReference($context, $envelope),
            fn (#[SensitiveParameter] string $key): GlobalEmailAddress => $this->codec->openRecipient($context, $envelope, $key),
        );
    }

    public function idempotencyKey(#[SensitiveParameter] GlobalIdentityOperation $claim): string
    {
        $hex = DB::table('global_identity_operations')->where([
            'id' => $claim->id,
            'delivery_attempt_id' => $claim->delivery_attempt_id,
            'delivery_fence_version' => $claim->delivery_fence_version,
        ])->selectRaw("encode(transport_idempotency_key, 'hex') AS encoded")->value('encoded');
        if (! is_string($hex) || preg_match('/\A[0-9a-f]{64}\z/', $hex) !== 1) {
            throw new GlobalIdentityKeyException('Global identity attempt key unavailable.');
        }
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new GlobalIdentityKeyException('Global identity attempt key unavailable.');
        }
        try {
            return sodium_bin2base64($bytes, GlobalIdentityEnvelopeCodec::BASE64);
        } finally {
            sodium_memzero($bytes);
        }
    }

    private function active(GlobalIdentityOperation $operation): bool
    {
        if ($operation->quarantined || $operation->delivery_state === GlobalIdentityDeliveryState::Terminal) {
            return false;
        }
        if (! $operation->expires_at->isFuture()) {
            $this->terminalize($operation, GlobalIdentityTerminalReason::Expired);

            return false;
        }

        return $operation->token_hash !== null;
    }

    private function terminalize(GlobalIdentityOperation $operation, GlobalIdentityTerminalReason $reason): void
    {
        $this->save($operation, [
            ...$this->clearAttempt(),
            'delivery_state' => GlobalIdentityDeliveryState::Terminal,
            'delivery_fence_version' => $operation->delivery_fence_version + 1,
            'terminal_reason' => $reason->value,
            'token_hash' => null,
            'delivery_token_enc' => null,
            'recipient_enc' => null,
        ]);
        $this->audit($operation, $reason->value);
    }

    private function clearTerminalResiduals(GlobalIdentityOperation $operation): void
    {
        if ($operation->delivery_token_enc === null && $operation->recipient_enc === null && $operation->token_hash === null
            && $operation->delivery_attempt_id === null && $operation->lease_expires_at === null
            && $operation->provider_message_reference === null) {
            return;
        }

        $reason = GlobalIdentityTerminalReason::tryFrom($operation->terminal_reason ?? '');
        $this->terminalize($operation, $reason ?? GlobalIdentityTerminalReason::IntegrityFailure);
    }

    private function expireLease(GlobalIdentityOperation $operation): void
    {
        if ($operation->dispatch_committed_at === null && $operation->delivery_state === GlobalIdentityDeliveryState::Claimed) {
            $this->retryable($operation, 'lease_expired');

            return;
        }

        $operation->delivery_fence_version++;
        $this->unknown($operation, 'commit_outcome_missing');
    }

    private function delivered(GlobalIdentityOperation $operation, #[SensitiveParameter] ?string $reference): void
    {
        $this->save($operation, [
            ...$this->clearAttempt(),
            'delivery_state' => GlobalIdentityDeliveryState::Delivered,
            'delivered_at' => now(),
            'delivery_token_enc' => null,
            'recipient_enc' => null,
            'provider_message_reference' => $reference,
        ]);
        $this->audit($operation, 'delivered');
    }

    private function retryable(GlobalIdentityOperation $operation, string $reason = 'before_acceptance'): void
    {
        $this->save($operation, [
            ...$this->clearAttempt(),
            'delivery_state' => GlobalIdentityDeliveryState::Retryable,
            'delivery_fence_version' => $operation->delivery_fence_version + 1,
            'last_delivery_error_class' => $reason,
        ]);
        $this->audit($operation, $reason);
    }

    private function unknown(GlobalIdentityOperation $operation, string $reason, #[SensitiveParameter] ?string $reference = null): void
    {
        $this->save($operation, [
            'delivery_state' => GlobalIdentityDeliveryState::Unknown,
            'lease_expires_at' => null,
            'last_delivery_error_class' => $reason,
            'provider_message_reference' => $reference ?? $operation->provider_message_reference,
        ]);
        $this->audit($operation, $reason);
    }

    /** @return array<string,null|false> */
    private function clearAttempt(): array
    {
        return [
            'delivery_attempt_id' => null,
            'transport_idempotency_key' => null,
            'transport_identity' => null,
            'uses_idempotency_key' => false,
            'lease_expires_at' => null,
            'provider_message_reference' => null,
            'last_delivery_error_class' => null,
        ];
    }

    private function matches(GlobalIdentityOperation $current, GlobalIdentityOperation $snapshot): bool
    {
        return $snapshot->delivery_attempt_id !== null
            && $current->delivery_attempt_id === $snapshot->delivery_attempt_id
            && $current->delivery_fence_version === $snapshot->delivery_fence_version;
    }

    private function storedRecipientPurpose(GlobalIdentityOperationContext $operation): ?GlobalIdentityWorkingKeyPurpose
    {
        if ($operation->type === GlobalIdentityOperationType::Invitation && $operation->userId !== null) {
            return null;
        }

        return $operation->type->recipientPurpose();
    }

    private function randomValue(): string
    {
        $bytes = random_bytes(32);
        try {
            return sodium_bin2base64($bytes, GlobalIdentityEnvelopeCodec::BASE64);
        } finally {
            sodium_memzero($bytes);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $decode
     * @return T
     */
    private function withDeliveryKey(GlobalIdentityWorkingKeyContext $reference, Closure $decode): mixed
    {
        $resolved = false;
        try {
            return $this->keys->withKey($reference, static function (#[SensitiveParameter] string $key) use (&$resolved, $decode): mixed {
                $resolved = true;

                return $decode($key);
            });
        } catch (GlobalIdentityKeyException $error) {
            if ($resolved) {
                throw $error;
            }
            throw new GlobalIdentityDeliveryKeyUnavailable('Exact global identity delivery key unavailable.');
        } catch (QueryException) {
            throw new GlobalIdentityDeliveryKeyUnavailable('Exact global identity delivery key unavailable.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(GlobalIdentityOperation): T  $use
     * @return T|null
     */
    private function locked(string $id, Closure $use): mixed
    {
        if (! GlobalIdentityOperationContext::isCanonicalId($id)) {
            return null;
        }
        try {
            return DB::transaction(function () use ($id, $use): mixed {
                $operation = GlobalIdentityOperation::query()->whereKey($id)->lockForUpdate()->first();

                return $operation === null ? null : $use($operation);
            });
        } catch (QueryException) {
            throw new GlobalIdentityKeyException('Global identity operation unavailable.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function save(#[SensitiveParameter] GlobalIdentityOperation $operation, #[SensitiveParameter] array $attributes): void
    {
        if (! $operation->forceFill($attributes)->save()) {
            throw new GlobalIdentityKeyException('Global identity operation write unavailable.');
        }
    }

    private function audit(GlobalIdentityOperation $operation, string $event): void
    {
        $fields = [
            'operation_id' => $operation->id,
            'state' => $operation->delivery_state->value,
            'event' => $event,
        ];
        DB::afterCommit(static fn () => Log::info('Global identity operation.', $fields));
    }
}
