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
        DB::transaction(function () use ($context): void {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', self::LOCK);
            $candidate = $this->exactRecord($context, 'activate');
            $this->verify($candidate, $context, 'activate');

            if ($candidate->state === GlobalIdentityWorkingKey::WRITE_ACTIVE) {
                return;
            }

            $purpose = $context->purpose;
            $active = $this->activeRecord($purpose, 'activate');
            $this->requireNextCandidate($candidate, $active, $purpose, 'activate');
            $this->verify($active, $this->context($purpose, $active), 'activate');

            if (! $active->update(['state' => GlobalIdentityWorkingKey::DECRYPT_ONLY])
                || ! $candidate->update(['state' => GlobalIdentityWorkingKey::WRITE_ACTIVE, 'activated_at' => now()])) {
                $this->reject('activate', 'update_failed', $purpose);
            }
            $this->auditCommit('activate', $context);
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
        return DB::transaction(function () use ($purpose, $use): mixed {
            DB::select('SELECT pg_advisory_xact_lock_shared(?, ?)', self::LOCK);
            $active = $this->activeRecord($purpose, 'resolve_active');
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
        $record = $this->exactRecord($context, 'resolve');
        if (! in_array($record->state, [GlobalIdentityWorkingKey::WRITE_ACTIVE, GlobalIdentityWorkingKey::DECRYPT_ONLY], true)) {
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
            $wrapped = $this->wrapper->wrap($key, $context, $root);
            $verified = $this->wrapper->unwrap($wrapped, $context);
            $verifiedAgainstRoot = $wrapped->root == $root && hash_equals($key, $verified);
        } catch (RandomException) {
            $this->reject($operation, 'generation_failed', $context->purpose);
        } catch (GlobalIdentityKeyException) {
            $this->reject($operation, 'invalid_envelope', $context->purpose);
        } finally {
            if (isset($key)) {
                sodium_memzero($key);
            }
            if (isset($verified)) {
                sodium_memzero($verified);
            }
        }

        if (! $verifiedAgainstRoot) {
            $this->reject($operation, 'verification_failed', $context->purpose);
        }

        $stored = GlobalIdentityWorkingKey::query()->create([
            'key_id' => $context->reference->id,
            'key_version' => $context->reference->version,
            'purpose' => $context->purpose->value,
            'algorithm' => $context->purpose->algorithm(),
            'state' => $state,
            'root_id' => $root->id,
            'root_version' => $root->version,
            'wrapping_format_version' => $wrapped->formatVersion,
            'wrapping_algorithm' => $wrapped->algorithm,
            'wrapping_nonce' => '\\x'.bin2hex($wrapped->nonce),
            'wrapped_key' => '\\x'.bin2hex($wrapped->ciphertextAndTag),
            'activated_at' => $state === GlobalIdentityWorkingKey::WRITE_ACTIVE ? now() : null,
        ]);
        if (! $stored->exists) {
            $this->reject($operation, 'insert_failed', $context->purpose);
        }

        $this->auditCommit($operation, $context);
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
