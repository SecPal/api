<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TenantKekRotationAuthority;
use App\Models\TenantKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TenantKekRegistry
{
    private const LOCK = [0x53454350, 0x544B454B];

    public function __construct(
        private TenantKekFile $files,
        private ?TenantKekRotationAuthority $authority = null,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function withLease(callable $operation, bool $exclusive = false): mixed
    {
        return DB::transaction(static function () use ($operation, $exclusive): mixed {
            DB::selectFromWriteConnection(
                $exclusive ? 'SELECT pg_advisory_xact_lock(?, ?)' : 'SELECT pg_advisory_xact_lock_shared(?, ?)',
                self::LOCK,
            );

            return $operation();
        });
    }

    /** Called under the exclusive lease by explicit provisioning. */
    public function initialize(): void
    {
        $configured = app(TenantKekReference::class);
        $current = $this->current();
        if ($current !== null && ! $current->matches($configured)) {
            throw new RuntimeException('Configured Tenant KEK does not match the committed generation.');
        }
        if ($current === null && DB::table('tenant_kek_generations')->exists()) {
            throw new RuntimeException('Tenant KEK history has no committed generation.');
        }

        $key = $this->files->load($configured, TenantKey::getKekPath());
        sodium_memzero($key);
        if ($current === null) {
            DB::table('tenant_kek_generations')->insert([
                'generation' => $configured->generation,
                'fingerprint' => $configured->fingerprint,
            ]);
            DB::table('tenant_kek_state')->insert([
                'singleton' => 1,
                'kek_id' => $configured->id,
                'generation' => $configured->generation,
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(string, TenantKekReference): T  $operation
     * @return T
     */
    public function withKey(callable $operation): mixed
    {
        return $this->withLease(function () use ($operation): mixed {
            $reference = app(TenantKekReference::class);
            if ($this->current()?->matches($reference) !== true) {
                throw new RuntimeException('Configured Tenant KEK does not match the committed generation.');
            }

            $key = $this->files->load($reference, TenantKey::getKekPath());
            try {
                return $operation($key, $reference);
            } finally {
                sodium_memzero($key);
            }
        });
    }

    public function rotate(string $operation): bool
    {
        if ($this->authority === null || ! Str::isUuid($operation) || strtolower($operation) !== $operation) {
            throw new RuntimeException('An explicitly approved Tenant KEK rotation operation is required.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Tenant KEK rotation requires an independent transaction.');
        }
        $plan = $this->authority->approvedPlan($operation);
        if ($plan->operationId !== $operation) {
            throw new RuntimeException('Tenant KEK rotation approval does not match the operation.');
        }

        return $this->withLease(function () use ($plan): bool {
            $current = $this->current();
            $recorded = DB::table('tenant_kek_generations')->where('rotation_id', $plan->operationId)->first();
            if ($recorded !== null) {
                $previous = DB::table('tenant_kek_generations')->where('generation', $plan->from->generation)->first();
                if ($current?->matches($plan->to) !== true || $recorded->generation !== $plan->to->generation
                    || $previous === null || $previous->fingerprint !== $plan->from->fingerprint) {
                    throw new RuntimeException('Tenant KEK operation no longer matches the committed transition.');
                }

                $target = $this->files->load($plan->to, $plan->targetPath);
                sodium_memzero($target);

                return false;
            }

            if ($current?->matches($plan->from) !== true) {
                throw new RuntimeException('Tenant KEK source generation has changed.');
            }
            if (DB::table('tenant_kek_generations')->where('fingerprint', $plan->to->fingerprint)->exists()) {
                throw new RuntimeException('Tenant KEK material has already been used.');
            }

            $source = $this->files->load($plan->from, $plan->sourcePath);
            try {
                $target = $this->files->load($plan->to, $plan->targetPath);
                try {
                    $this->rewrap($plan, $source, $target);
                } finally {
                    sodium_memzero($target);
                }
            } finally {
                sodium_memzero($source);
            }

            return true;
        }, exclusive: true);
    }

    private function rewrap(
        TenantKekRotationPlan $plan,
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] string $target,
    ): void {
        DB::statement('SET CONSTRAINTS tenant_keys_kek_reference DEFERRED');

        foreach (TenantKey::query()->lockForUpdate()->lazyById() as $tenantKey) {
            if (! $tenantKey->update($tenantKey->rewrappedEnvelopes($plan, $source, $target))) {
                throw new RuntimeException('Tenant key rewrap could not be stored.');
            }
        }

        DB::table('tenant_kek_generations')->insert([
            'generation' => $plan->to->generation,
            'fingerprint' => $plan->to->fingerprint,
            'rotation_id' => $plan->operationId,
        ]);
        $updated = DB::table('tenant_kek_state')
            ->where('singleton', 1)
            ->where('kek_id', $plan->from->id)
            ->where('generation', $plan->from->generation)
            ->update(['generation' => $plan->to->generation]);
        if ($updated !== 1) {
            throw new RuntimeException('Tenant KEK generation could not be committed.');
        }

        DB::statement('SET CONSTRAINTS tenant_keys_kek_reference IMMEDIATE');
    }

    private function current(): ?TenantKekReference
    {
        $row = DB::table('tenant_kek_state as s')->useWritePdo()
            ->join('tenant_kek_generations as g', 's.generation', '=', 'g.generation')
            ->where('s.singleton', 1)
            ->first(['s.kek_id', 's.generation', 'g.fingerprint']);

        if ($row === null) {
            return null;
        }
        if (! is_string($row->kek_id) || ! is_int($row->generation) || ! is_string($row->fingerprint)) {
            throw new RuntimeException('Invalid committed Tenant KEK generation.');
        }

        return new TenantKekReference($row->kek_id, $row->generation, $row->fingerprint);
    }
}
