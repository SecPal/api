<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Contracts\GlobalIdentityKeyWrapper;
use App\Exceptions\GlobalIdentityKeyException;
use App\Models\GlobalIdentityWorkingKey;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\RandomException;
use SensitiveParameter;

final readonly class GlobalIdentityWorkingKeyRegistry
{
    // The two-integer advisory lock namespace is separate from the tenant locks.
    private const LOCK = [0x53454350, 0x47494B52];

    public function __construct(private GlobalIdentityKeyWrapper $wrapper) {}

    public function initialize(GlobalIdentityKeyReference $root): void
    {
        DB::transaction(function () use ($root): void {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $initialized = GlobalIdentityWorkingKey::query()->exists();
            if (! $initialized && (DB::table('users')->exists() || DB::table('global_identity_operations')->exists())) {
                throw new GlobalIdentityKeyException('Existing identity state requires its original working-key registry.');
            }

            foreach (GlobalIdentityWorkingKeyPurpose::cases() as $purpose) {
                if ($initialized) {
                    $active = $this->activeRecord($purpose, 'initialize');
                    if ($active->root_id !== $root->id || $active->root_version !== $root->version) {
                        $this->reject('initialize', 'different_root', $purpose);
                    }
                    $this->verify($active, $this->context($purpose, $active), 'initialize');

                    continue;
                }

                $context = new GlobalIdentityWorkingKeyContext(
                    $purpose,
                    new GlobalIdentityKeyReference((string) Str::uuid(), 1),
                );
                $this->insert($context, $root, GlobalIdentityWorkingKey::WRITE_ACTIVE, 'initialize');
            }
        });
    }

    public function stage(GlobalIdentityWorkingKeyPurpose $purpose): GlobalIdentityWorkingKeyContext
    {
        return DB::transaction(function () use ($purpose): GlobalIdentityWorkingKeyContext {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $active = $this->activeRecord($purpose, 'stage');
            if ($active->delivery_block_reason !== null) {
                $this->reject('stage', 'blocked_version', $purpose);
            }
            $this->verify($active, $this->context($purpose, $active), 'stage');

            $candidate = GlobalIdentityWorkingKey::envelopeQuery()->where('purpose', $purpose->value)
                ->where('state', GlobalIdentityWorkingKey::CANDIDATE)->first();
            if ($candidate !== null) {
                $this->requireNextCandidate($candidate, $active, $purpose, 'stage');
                $context = $this->context($purpose, $candidate);
                $this->verify($candidate, $context, 'stage');

                return $context;
            }

            $context = new GlobalIdentityWorkingKeyContext(
                $purpose,
                new GlobalIdentityKeyReference($active->key_id, $active->key_version + 1),
            );
            $this->insert(
                $context,
                new GlobalIdentityKeyReference($active->root_id, $active->root_version),
                GlobalIdentityWorkingKey::CANDIDATE,
                'stage',
            );

            return $context;
        });
    }

    public function activate(GlobalIdentityWorkingKeyContext $context): void
    {
        $this->activateCandidate($context, rotateLookup: false);
    }

    public function activateLookupRotation(GlobalIdentityWorkingKeyContext $context): void
    {
        if ($context->purpose !== GlobalIdentityWorkingKeyPurpose::UserEmailLookup) {
            throw new GlobalIdentityKeyException('Invalid lookup rotation key purpose.');
        }
        $this->activateCandidate($context, rotateLookup: true);
    }

    /** @param null|Closure(): bool $revocationComplete */
    private function activateCandidate(GlobalIdentityWorkingKeyContext $context, bool $rotateLookup, ?Closure $revocationComplete = null): void
    {
        DB::transaction(function () use ($context, $rotateLookup, $revocationComplete): void {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $candidate = $this->exactRecord($context, 'activate');
            $this->verify($candidate, $context, 'activate');

            if ($candidate->state === GlobalIdentityWorkingKey::WRITE_ACTIVE) {
                return;
            }

            $purpose = $context->purpose;
            $active = $this->activeRecord($purpose, 'activate');
            $this->requireNextCandidate($candidate, $active, $purpose, 'activate');
            if ($active->delivery_block_reason === null) {
                $this->verify($active, $this->context($purpose, $active), 'activate');
            } elseif ($revocationComplete === null || ! $revocationComplete()) {
                $this->reject('activate', 'incident_revocation_incomplete', $purpose);
            }
            if ($active->previous_lookup_version !== null) {
                $this->reject('activate', 'lookup_rotation_active', $purpose);
            }

            if (! $active->update(['state' => GlobalIdentityWorkingKey::DECRYPT_ONLY])
                || ! $candidate->update([
                    'state' => GlobalIdentityWorkingKey::WRITE_ACTIVE,
                    'activated_at' => now(),
                    'previous_lookup_version' => $rotateLookup ? $active->key_version : null,
                ])) {
                $this->reject('activate', 'update_failed', $purpose);
            }
            $this->auditCommit('activate', $context);
        });
    }

    /** @param Closure(): bool $revocationComplete */
    public function activateDeliveryReplacement(GlobalIdentityWorkingKeyContext $context, Closure $revocationComplete): void
    {
        if ($context->purpose !== GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret) {
            throw new GlobalIdentityKeyException('Invalid delivery replacement key purpose.');
        }
        $this->activateCandidate($context, rotateLookup: false, revocationComplete: $revocationComplete);
    }

    public function blockDeliveryVersion(
        GlobalIdentityKeyReference $source,
        GlobalIdentityTerminalReason $reason,
        ?GlobalIdentityKeyReference $expectedTarget = null,
    ): void {
        if (! in_array($reason, [GlobalIdentityTerminalReason::SecurityRevoked, GlobalIdentityTerminalReason::KeyUnavailable], true)) {
            throw new GlobalIdentityKeyException('Invalid delivery incident reason.');
        }

        DB::transaction(function () use ($source, $reason, $expectedTarget): void {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
            $active = $this->activeRecord($purpose, 'block_delivery');
            $activeReference = $this->context($purpose, $active)->reference;
            $context = new GlobalIdentityWorkingKeyContext($purpose, $source);
            $affected = $this->exactRecord($context, 'block_delivery');
            $target = $activeReference == $source
                ? new GlobalIdentityKeyReference($source->id, $source->version + 1)
                : $activeReference;
            if (! in_array($affected->state, [GlobalIdentityWorkingKey::WRITE_ACTIVE, GlobalIdentityWorkingKey::DECRYPT_ONLY], true)
                || ($expectedTarget !== null && $expectedTarget != $target)
                || ($affected->delivery_block_reason !== null && $affected->delivery_block_reason !== $reason->value)) {
                $this->reject('block_delivery', 'unexpected_source', $purpose);
            }
            if (! $affected->update(['delivery_block_reason' => $reason->value])) {
                $this->reject('block_delivery', 'update_failed', $purpose);
            }
            $this->auditCommit('block_delivery', $context);
        });
    }

    public function stageBlockedDeliveryReplacement(GlobalIdentityKeyReference $source): GlobalIdentityWorkingKeyContext
    {
        return DB::transaction(function () use ($source): GlobalIdentityWorkingKeyContext {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $purpose = GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret;
            $active = $this->activeRecord($purpose, 'stage_delivery_replacement');
            if ($active->delivery_block_reason === null || $this->context($purpose, $active)->reference != $source) {
                $this->reject('stage_delivery_replacement', 'unexpected_source', $purpose);
            }
            $candidate = GlobalIdentityWorkingKey::envelopeQuery()->where('purpose', $purpose->value)
                ->where('state', GlobalIdentityWorkingKey::CANDIDATE)->first();
            $target = new GlobalIdentityWorkingKeyContext($purpose, new GlobalIdentityKeyReference($source->id, $source->version + 1));
            if ($candidate !== null) {
                $this->requireNextCandidate($candidate, $active, $purpose, 'stage_delivery_replacement');
                $this->verify($candidate, $target, 'stage_delivery_replacement');
            } else {
                $this->insert($target, new GlobalIdentityKeyReference($active->root_id, $active->root_version),
                    GlobalIdentityWorkingKey::CANDIDATE, 'stage_delivery_replacement');
            }

            return $target;
        });
    }

    public function deliveryBlockReason(string $keyId, int $version): ?GlobalIdentityTerminalReason
    {
        return $this->withReadLease(function () use ($keyId, $version): ?GlobalIdentityTerminalReason {
            $context = new GlobalIdentityWorkingKeyContext(
                GlobalIdentityWorkingKeyPurpose::OperationDeliverySecret,
                new GlobalIdentityKeyReference($keyId, $version),
            );
            $row = $this->exactRecord($context, 'delivery_fence');

            return $row->delivery_block_reason === null ? null : GlobalIdentityTerminalReason::from($row->delivery_block_reason);
        });
    }

    /**
     * Hold the lookup epoch through the authorized repository operation.
     *
     * @template T
     *
     * @param  Closure(GlobalEmailLookupVersions): T  $use
     * @return T
     */
    public function withLookupVersions(Closure $use): mixed
    {
        return $this->withReadLease(function () use ($use): mixed {
            $active = $this->activeRecord(GlobalIdentityWorkingKeyPurpose::UserEmailLookup, 'lookup_versions');
            $write = $active->key_version;
            $previous = $active->lookup_cleanup ? null : $active->previous_lookup_version;

            return $use(new GlobalEmailLookupVersions($write, $previous === null ? [$write] : [$previous, $write]));
        });
    }

    public function lookupCleanupVersion(): ?int
    {
        return $this->withReadLease(function (): ?int {
            $active = $this->activeRecord(GlobalIdentityWorkingKeyPurpose::UserEmailLookup, 'lookup_cleanup');

            return $active->lookup_cleanup ? $active->previous_lookup_version : null;
        });
    }

    /**
     * @param  Closure(int, int): void  $verifyProjection
     * @param  Closure(int, int): bool  $removePriorProjection
     */
    public function completeLookupRotation(
        GlobalIdentityWorkingKeyContext $target,
        Closure $verifyProjection,
        Closure $removePriorProjection,
    ): bool {
        return DB::transaction(function () use ($target, $verifyProjection, $removePriorProjection): bool {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $purpose = GlobalIdentityWorkingKeyPurpose::UserEmailLookup;
            $active = $this->activeRecord($purpose, 'complete_lookup');
            if ($target != $this->context($purpose, $active)) {
                $this->reject('complete_lookup', 'stale_target', $purpose);
            }
            if ($active->previous_lookup_version === null) {
                return true;
            }
            $prior = $active->previous_lookup_version;
            if (! $active->lookup_cleanup) {
                $verifyProjection($prior, $active->key_version);
                if (! $active->update(['lookup_cleanup' => true])) {
                    $this->reject('complete_lookup', 'update_failed', $purpose);
                }
                $this->auditCommit('cutover_lookup', $target);
            }
            if (! $removePriorProjection($prior, $active->key_version)) {
                return false;
            }
            if (! $active->update(['previous_lookup_version' => null, 'lookup_cleanup' => false])) {
                $this->reject('complete_lookup', 'update_failed', $purpose);
            }
            $this->auditCommit('complete_lookup', $target);

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $use
     * @return T
     */
    public function withReadLease(Closure $use): mixed
    {
        return DB::transaction(function () use ($use): mixed {
            DB::select('SELECT pg_advisory_xact_lock_shared(?, ?)', self::LOCK);

            return $use();
        });
    }

    public function activeReference(GlobalIdentityWorkingKeyPurpose $purpose): GlobalIdentityKeyReference
    {
        return $this->withReadLease(function () use ($purpose): GlobalIdentityKeyReference {
            $active = $this->activeRecord($purpose, 'active_reference');

            return new GlobalIdentityKeyReference($active->key_id, $active->key_version);
        });
    }

    /** @param Closure(): bool $hasLiveReferences */
    public function retire(GlobalIdentityWorkingKeyContext $context, Closure $hasLiveReferences): void
    {
        DB::transaction(function () use ($context, $hasLiveReferences): void {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $row = $this->exactRecord($context, 'retire');
            if ($hasLiveReferences()) {
                $this->reject('retire', 'live_references', $context->purpose);
            }
            if ($row->state === GlobalIdentityWorkingKey::RETIRED) {
                return;
            }
            if ($row->state !== GlobalIdentityWorkingKey::DECRYPT_ONLY
                || ! $row->update(['state' => GlobalIdentityWorkingKey::RETIRED])) {
                $this->reject('retire', 'invalid_state', $context->purpose);
            }
            $this->auditCommit('retire', $context);
        });
    }

    /** @param Closure(): bool $hasLiveReferences */
    public function activateDrainedReservationKey(GlobalIdentityWorkingKeyContext $target, Closure $hasLiveReferences): bool
    {
        if ($target->purpose !== GlobalIdentityWorkingKeyPurpose::UserEmailReservation) {
            throw new GlobalIdentityKeyException('Invalid reservation rotation key.');
        }

        return DB::transaction(function () use ($target, $hasLiveReferences): bool {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            if ($hasLiveReferences()) {
                return false;
            }
            $this->activate($target);

            return true;
        });
    }

    /** The persisted exact root reference is the checkpoint for each row. */
    public function rewrapRootBatch(
        GlobalIdentityKeyReference $from,
        GlobalIdentityKeyReference $to,
        int $limit,
    ): int {
        if ($from == $to || $limit < 1 || $limit > 500) {
            throw new GlobalIdentityKeyException('Invalid global identity root rewrap batch.');
        }

        return DB::transaction(function () use ($from, $to, $limit): int {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $rows = GlobalIdentityWorkingKey::envelopeQuery()->addSelect('purpose')
                ->where('root_id', $from->id)->where('root_version', $from->version)
                ->orderBy('id')->limit($limit)->lockForUpdate()->get();

            foreach ($rows as $row) {
                $purpose = GlobalIdentityWorkingKeyPurpose::tryFrom($row->purpose)
                    ?? throw new GlobalIdentityKeyException('Unknown global identity working key purpose.');
                $context = $this->context($purpose, $row);
                $key = $this->unwrap($row, $context, 'rewrap');
                try {
                    $wrapped = $this->wrapVerified($key, $context, $to, 'rewrap');
                    if (! $row->update($this->wrappedAttributes($wrapped))) {
                        $this->reject('rewrap', 'update_failed', $purpose);
                    }
                } finally {
                    sodium_memzero($key);
                }
                $this->auditCommit('rewrap', $context);
            }

            return $rows->count();
        });
    }

    /**
     * Use the active key for bounded cryptography and associated writes on this
     * database connection. Activation waits for the enclosing transaction to finish.
     * Mark the callback's key parameter sensitive and keep the bytes in the callback.
     *
     * @template T
     *
     * @param  Closure(string, GlobalIdentityWorkingKeyContext): T  $use
     * @return T
     */
    public function withActiveKey(GlobalIdentityWorkingKeyPurpose $purpose, Closure $use): mixed
    {
        return $this->withReadLease(function () use ($purpose, $use): mixed {
            $active = $this->activeRecord($purpose, 'resolve_active');
            if ($active->delivery_block_reason !== null) {
                $this->reject('resolve_active', 'blocked_version', $purpose);
            }
            $context = $this->context($purpose, $active);
            $key = $this->unwrap($active, $context, 'resolve_active');

            try {
                return $use($key, $context);
            } finally {
                sodium_memzero($key);
            }
        });
    }

    /**
     * Resolve only an exact, previously activated version.
     *
     * @template T
     *
     * @param  Closure(string, GlobalIdentityWorkingKeyContext): T  $use
     * @return T
     */
    public function withKey(GlobalIdentityWorkingKeyContext $context, Closure $use): mixed
    {
        return $this->withReadLease(function () use ($context, $use): mixed {
            $record = $this->exactRecord($context, 'resolve');

            return $this->withReadableRecord($record, $context, $use);
        });
    }

    /**
     * Resolve a purpose for which the stored reference is the uniquely indexed version.
     *
     * @template T
     *
     * @param  Closure(string, GlobalIdentityWorkingKeyContext): T  $use
     * @return T
     */
    public function withPurposeVersion(GlobalIdentityWorkingKeyPurpose $purpose, int $version, Closure $use): mixed
    {
        return $this->withReadLease(function () use ($purpose, $version, $use): mixed {
            $record = GlobalIdentityWorkingKey::envelopeQuery()->where('purpose', $purpose->value)
                ->where('key_version', $version)->first()
                ?? $this->reject('resolve', 'missing_version', $purpose);

            return $this->withReadableRecord($record, $this->context($purpose, $record), $use);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(string, GlobalIdentityWorkingKeyContext): T  $use
     * @return T
     */
    private function withReadableRecord(GlobalIdentityWorkingKey $record, GlobalIdentityWorkingKeyContext $context, Closure $use): mixed
    {
        if ($record->delivery_block_reason !== null
            || ! in_array($record->state, [GlobalIdentityWorkingKey::WRITE_ACTIVE, GlobalIdentityWorkingKey::DECRYPT_ONLY], true)) {
            $this->reject('resolve', 'unreadable_state', $context->purpose);
        }

        $key = $this->unwrap($record, $context, 'resolve');
        try {
            return $use($key, $context);
        } finally {
            sodium_memzero($key);
        }
    }

    private function requireNextCandidate(
        GlobalIdentityWorkingKey $candidate,
        GlobalIdentityWorkingKey $active,
        GlobalIdentityWorkingKeyPurpose $purpose,
        string $operation,
    ): void {
        if ($candidate->state !== GlobalIdentityWorkingKey::CANDIDATE
            || $candidate->key_id !== $active->key_id
            || $candidate->key_version !== $active->key_version + 1
            || $candidate->root_id !== $active->root_id
            || $candidate->root_version !== $active->root_version) {
            $this->reject($operation, 'invalid_candidate', $purpose);
        }
    }

    private function activeRecord(GlobalIdentityWorkingKeyPurpose $purpose, string $operation): GlobalIdentityWorkingKey
    {
        return GlobalIdentityWorkingKey::envelopeQuery()->where('purpose', $purpose->value)
            ->where('state', GlobalIdentityWorkingKey::WRITE_ACTIVE)->sharedLock()->first()
            ?? $this->reject($operation, 'missing_active', $purpose);
    }

    private function exactRecord(GlobalIdentityWorkingKeyContext $context, string $operation): GlobalIdentityWorkingKey
    {
        if (! Str::isUuid($context->reference->id)) {
            $this->reject($operation, 'missing_version', $context->purpose);
        }

        return GlobalIdentityWorkingKey::envelopeQuery()->where('purpose', $context->purpose->value)
            ->where('key_id', $context->reference->id)->where('key_version', $context->reference->version)->first()
            ?? $this->reject($operation, 'missing_version', $context->purpose);
    }

    private function context(GlobalIdentityWorkingKeyPurpose $purpose, GlobalIdentityWorkingKey $row): GlobalIdentityWorkingKeyContext
    {
        return new GlobalIdentityWorkingKeyContext($purpose, new GlobalIdentityKeyReference($row->key_id, $row->key_version));
    }

    private function verify(
        #[SensitiveParameter] GlobalIdentityWorkingKey $row,
        GlobalIdentityWorkingKeyContext $context,
        string $operation,
    ): void {
        $key = $this->unwrap($row, $context, $operation);
        sodium_memzero($key);
    }

    private function unwrap(
        #[SensitiveParameter] GlobalIdentityWorkingKey $row,
        GlobalIdentityWorkingKeyContext $context,
        string $operation,
    ): string {
        if ($row->algorithm !== $context->purpose->algorithm()) {
            $this->reject($operation, 'invalid_algorithm', $context->purpose);
        }

        // Both hexadecimal representations come from PostgreSQL encode(bytea).
        $nonce = hex2bin($row->nonce_hex);
        $wrapped = hex2bin($row->key_hex);
        if ($nonce === false || $wrapped === false) {
            $this->reject($operation, 'invalid_envelope', $context->purpose);
        }

        try {
            return $this->wrapper->unwrap(new WrappedGlobalIdentityKey(
                $row->wrapping_format_version,
                $row->wrapping_algorithm,
                new GlobalIdentityKeyReference($row->root_id, $row->root_version),
                $nonce,
                $wrapped,
            ), $context);
        } catch (GlobalIdentityKeyException) {
            $this->reject($operation, 'invalid_envelope', $context->purpose);
        }
    }

    private function insert(
        GlobalIdentityWorkingKeyContext $context,
        GlobalIdentityKeyReference $root,
        string $state,
        string $operation,
    ): void {
        try {
            $key = random_bytes(32);
        } catch (RandomException) {
            $this->reject($operation, 'generation_failed', $context->purpose);
        }
        try {
            $wrapped = $this->wrapVerified($key, $context, $root, $operation);
        } finally {
            sodium_memzero($key);
        }

        $stored = GlobalIdentityWorkingKey::query()->create([
            'key_id' => $context->reference->id,
            'key_version' => $context->reference->version,
            'purpose' => $context->purpose->value,
            'algorithm' => $context->purpose->algorithm(),
            'state' => $state,
            ...$this->wrappedAttributes($wrapped),
            'activated_at' => $state === GlobalIdentityWorkingKey::WRITE_ACTIVE ? now() : null,
        ]);
        if (! $stored->exists) {
            $this->reject($operation, 'insert_failed', $context->purpose);
        }

        $this->auditCommit($operation, $context);
    }

    private function wrapVerified(
        #[SensitiveParameter] string $key,
        GlobalIdentityWorkingKeyContext $context,
        GlobalIdentityKeyReference $root,
        string $operation,
    ): WrappedGlobalIdentityKey {
        try {
            $wrapped = $this->wrapper->wrap($key, $context, $root);
            $verified = $this->wrapper->unwrap($wrapped, $context);
            $matches = $wrapped->root == $root && hash_equals($key, $verified);
        } catch (GlobalIdentityKeyException) {
            $this->reject($operation, 'invalid_envelope', $context->purpose);
        } finally {
            if (isset($verified)) {
                sodium_memzero($verified);
            }
        }

        if (! $matches) {
            $this->reject($operation, 'verification_failed', $context->purpose);
        }

        return $wrapped;
    }

    /** @return array<string, int|string> */
    private function wrappedAttributes(#[SensitiveParameter] WrappedGlobalIdentityKey $wrapped): array
    {
        return [
            'root_id' => $wrapped->root->id,
            'root_version' => $wrapped->root->version,
            'wrapping_format_version' => $wrapped->formatVersion,
            'wrapping_algorithm' => $wrapped->algorithm,
            'wrapping_nonce' => '\\x'.bin2hex($wrapped->nonce),
            'wrapped_key' => '\\x'.bin2hex($wrapped->ciphertextAndTag),
        ];
    }

    private function auditCommit(string $operation, GlobalIdentityWorkingKeyContext $context): void
    {
        DB::afterCommit(static fn () => Log::info('Global identity working key updated.', [
            'operation' => $operation,
            'purpose' => $context->purpose->value,
            'version' => $context->reference->version,
        ]));
    }

    private function reject(string $operation, string $reason, GlobalIdentityWorkingKeyPurpose $purpose): never
    {
        Log::warning('Global identity working key rejected.', [
            'operation' => $operation,
            'reason' => $reason,
            'purpose' => $purpose->value,
        ]);

        throw new GlobalIdentityKeyException('Global identity working key unavailable.');
    }
}
