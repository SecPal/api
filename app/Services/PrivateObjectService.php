<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OnboardingSubmissionFile;
use App\Models\PrivateObject;
use App\Models\TenantKey;
use App\Storage\ImmutablePrivateObjectBackend;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * API-owned application catalog for deployment#99's normative Object contract.
 * No callback, commit, rollback, Secret Authority or physical GC interface.
 */
class PrivateObjectService
{
    public function __construct(private readonly ImmutablePrivateObjectBackend $backend) {}

    /** @return array{id: string, object_key: string, storage_sha256: string, stored_bytes: int} */
    public function stage(TenantKey $tenant, string $purpose, string $plaintext): array
    {
        $tenant = TenantKey::query()->findOrFail($tenant->id);
        $encrypted = $tenant->encrypt($plaintext);
        $blob = json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ], JSON_THROW_ON_ERROR);

        return $this->stageEncrypted($tenant, $purpose, $blob);
    }

    /**
     * Also used by the explicit legacy import, never as a plaintext fallback.
     * Admit the existing TenantKey crypto boundary, not draft #337/#338 code.
     *
     * @return array{id: string, object_key: string, storage_sha256: string, stored_bytes: int}
     */
    public function stageEncrypted(TenantKey $tenant, string $purpose, string $blob): array
    {
        $this->purpose($purpose);
        $tenant = TenantKey::query()->findOrFail($tenant->id);
        $this->decrypt($tenant, $blob); // Authenticated ciphertext before provider IO.
        $identity = $this->backend->identity();
        $targets = config('private_objects.replication_targets');
        if (! is_array($targets) || $targets === [] || count($targets) > 64) {
            throw new RuntimeException('Private Object replication ownership is missing');
        }
        $validatedTargets = [];
        foreach ($targets as $target) {
            if (! is_string($target) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', $target) !== 1) {
                throw new RuntimeException('Private Object replication target is invalid');
            }
            $validatedTargets[] = $target;
        }
        if (count(array_unique($validatedTargets)) !== count($validatedTargets)) {
            throw new RuntimeException('Private Object replication targets are duplicated');
        }
        $id = (string) Str::uuid();
        $key = str_replace('-', '', (string) Str::uuid());
        $hash = hash('sha256', $blob);
        $size = strlen($blob);
        DB::table('private_objects')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'purpose' => $purpose,
            'object_key' => $key, 'backend_identity' => $identity,
            'state' => 'PENDING', 'storage_sha256' => $hash, 'stored_bytes' => $size,
            'crypto' => json_encode([
                'scheme' => 'sodium-secretbox-v1', 'generation' => $tenant->key_version,
                'key_reference' => 'tenant-key/'.$tenant->id.'/'.$tenant->key_version,
                'wrapped_dek' => base64_encode($tenant->dek_wrapped),
                'wrapped_nonce' => base64_encode($tenant->dek_nonce),
            ], JSON_THROW_ON_ERROR),
            'replication_targets' => json_encode($validatedTargets, JSON_THROW_ON_ERROR),
        ]);
        try {
            $this->backend->create($key, $blob);
            $observed = $this->backend->read($key, $size);
            if (! hash_equals($hash, hash('sha256', $observed))) {
                throw new RuntimeException('Private Object integrity mismatch');
            }
        } catch (\Throwable) {
            // No GET-after-error durability inference, retry/overwrite or delete.
            // The PENDING reservation and possible Object remain for read-back/GC.
            throw new RuntimeException('Private Object durable publication failed');
        }
        DB::table('private_objects')->where('id', $id)->update(['verified_at' => now()]);

        return ['id' => $id, 'object_key' => $key, 'storage_sha256' => $hash, 'stored_bytes' => $size];
    }

    public function publish(string $objectId, TenantKey $tenant, string $purpose, string $businessId): void
    {
        $this->transaction();
        $column = $this->purpose($purpose);
        $object = PrivateObject::query()->where('id', $objectId)
            ->where('tenant_id', $tenant->id)->where('purpose', $purpose)->lockForUpdate()->first();
        if ($object === null) {
            throw new RuntimeException('Private Object scope is not authorized');
        }
        $ownerTenant = $this->ownerTenant($purpose, $businessId, includeTrashed: true);
        if ($ownerTenant !== (int) $tenant->id) {
            throw new RuntimeException('Private Object business owner is not authorized');
        }
        if ($object->state === 'READY') {
            if (DB::table('private_object_references')->where('object_id', $objectId)
                ->where($column, $businessId)->whereNull('tombstoned_at')->exists()) {
                return; // Independently read-back the exact committed result.
            }
            throw new RuntimeException('Private Object already has another owner');
        }
        if ($object->state !== 'PENDING' || $object->verified_at === null) {
            throw new RuntimeException('Private Object has no successful durable publication');
        }
        $this->verifiedBytes($object);
        DB::table('private_object_references')->insert(['object_id' => $objectId, $column => $businessId]);
        foreach ($object->replication_targets as $target) {
            DB::table('object_replication_intents')->insert(['object_id' => $objectId, 'target' => $target]);
        }
        DB::table('private_objects')->where('id', $objectId)->update(['state' => 'READY']);
    }

    public function read(TenantKey $tenant, string $purpose, string $businessId): string
    {
        $column = $this->purpose($purpose);
        if ($this->ownerTenant($purpose, $businessId) !== (int) $tenant->id) {
            throw new RuntimeException('Private Object business owner is not authorized');
        }
        $object = PrivateObject::query()->from('private_objects as o')->join('private_object_references as r', 'r.object_id', '=', 'o.id')
            ->where('o.tenant_id', $tenant->id)->where('o.purpose', $purpose)->where('o.state', 'READY')
            ->where('r.'.$column, $businessId)->whereNull('r.tombstoned_at')->select('o.*')->first();
        if ($object === null) {
            throw new RuntimeException('Private Object is unavailable');
        }
        $crypto = $object->crypto;
        if ($crypto['scheme'] !== 'sodium-secretbox-v1'
            || $crypto['key_reference'] !== 'tenant-key/'.$tenant->id.'/'.$crypto['generation']) {
            throw new RuntimeException('Private Object crypto identity is invalid');
        }
        $wrapped = base64_decode($crypto['wrapped_dek'], true);
        $nonce = base64_decode($crypto['wrapped_nonce'], true);
        if ($wrapped === false || $nonce === false) {
            throw new RuntimeException('Private Object key metadata is invalid');
        }
        // Reuse the accepted TenantKey implementation with its retained wrapped
        // generation. No unwrapped key is persisted or delivered to a provider.
        $generation = new TenantKey([
            'dek_wrapped' => $wrapped, 'dek_nonce' => $nonce, 'key_version' => $crypto['generation'],
        ]);

        return $this->decrypt($generation, $this->verifiedBytes($object));
    }

    public function tombstone(TenantKey $tenant, string $purpose, string $businessId): void
    {
        $this->transaction();
        $column = $this->purpose($purpose);
        if ($this->ownerTenant($purpose, $businessId, includeTrashed: true) !== (int) $tenant->id) {
            throw new RuntimeException('Private Object business owner is not authorized');
        }
        $reference = DB::table('private_object_references')->where($column, $businessId)->first();
        if ($reference === null) {
            return; // Legacy metadata has no Object authority or physical-delete fallback.
        }
        $object = PrivateObject::query()->where('id', $reference->object_id)
            ->where('tenant_id', $tenant->id)->where('purpose', $purpose)->lockForUpdate()->first();
        if ($object === null || ! in_array($object->state, ['READY', 'TOMBSTONED'], true)) {
            throw new RuntimeException('Private Object scope is not authorized');
        }
        DB::table('private_object_references')->where('object_id', $object->id)->whereNull('tombstoned_at')->update(['tombstoned_at' => now()]);
        DB::table('private_objects')->where('id', $object->id)->update(['state' => 'TOMBSTONED']);
    }

    /** Called inside the existing lawful business-retention transaction. */
    public function tombstoneEmployee(Employee $employee): void
    {
        $this->transaction();
        $references = DB::table('private_object_references as r')
            ->leftJoin('employee_documents as d', 'd.id', '=', 'r.employee_document_id')
            ->leftJoin('onboarding_submission_files as f', 'f.id', '=', 'r.onboarding_submission_file_id')
            ->leftJoin('onboarding_form_submissions as s', 's.id', '=', 'f.onboarding_form_submission_id')
            ->where(fn (Builder $query) => $query->where('d.employee_id', $employee->id)->orWhere('s.employee_id', $employee->id))
            ->orderBy('r.object_id')->get(['r.employee_document_id', 'r.onboarding_submission_file_id']);
        if ($references->isEmpty()) {
            return;
        }
        $tenant = $employee->tenant;
        if ($tenant === null) {
            throw new RuntimeException('Private Object retention tenant is unavailable');
        }
        foreach ($references as $reference) {
            if (is_string($reference->employee_document_id)) {
                $this->tombstone($tenant, 'employee-document', $reference->employee_document_id);
            } elseif (is_string($reference->onboarding_submission_file_id)) {
                $this->tombstone($tenant, 'onboarding-file', $reference->onboarding_submission_file_id);
            } else {
                throw new RuntimeException('Private Object retention reference is invalid');
            }
        }
    }

    private function verifiedBytes(PrivateObject $object): string
    {
        if (! hash_equals($object->backend_identity, $this->backend->identity())) {
            throw new RuntimeException('Private Object namespace differs from its reservation');
        }
        try {
            $bytes = $this->backend->read($object->object_key, (int) $object->stored_bytes);
        } catch (\Throwable) {
            throw new RuntimeException('Private Object read or integrity verification failed');
        }
        if (! hash_equals($object->storage_sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Private Object read or integrity verification failed');
        }

        return $bytes;
    }

    private function decrypt(TenantKey $tenant, string $blob): string
    {
        $value = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_keys($value) !== ['ciphertext', 'nonce']
            || ! is_string($value['ciphertext']) || ! is_string($value['nonce'])) {
            throw new RuntimeException('Private Object ciphertext representation is invalid');
        }
        $ciphertext = base64_decode($value['ciphertext'], true);
        $nonce = base64_decode($value['nonce'], true);
        if ($ciphertext === false || $nonce === false) {
            throw new RuntimeException('Private Object ciphertext representation is invalid');
        }

        return $tenant->decrypt($ciphertext, $nonce);
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Private Object publication requires its caller-owned transaction');
        }
    }

    private function purpose(string $purpose): string
    {
        if (! in_array(config('private_objects.purpose'), ['api', 'worker'], true)) {
            throw new RuntimeException('Private Object runtime purpose is not authorized');
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Private Objects require PostgreSQL');
        }

        return match ($purpose) {
            'employee-document' => 'employee_document_id',
            'onboarding-file' => 'onboarding_submission_file_id',
            default => throw new RuntimeException('Private Object purpose is not authorized'),
        };
    }

    private function ownerTenant(string $purpose, string $businessId, bool $includeTrashed = false): ?int
    {
        $value = $purpose === 'employee-document'
            ? EmployeeDocument::query()->when($includeTrashed, fn ($query) => $query->withTrashed())->join('employees', 'employees.id', '=', 'employee_documents.employee_id')
                ->where('employee_documents.id', $businessId)->value('employees.tenant_id')
            : OnboardingSubmissionFile::query()->when($includeTrashed, fn ($query) => $query->withTrashed())
                ->join('onboarding_form_submissions as s', 's.id', '=', 'onboarding_submission_files.onboarding_form_submission_id')
                ->join('employees as e', 'e.id', '=', 's.employee_id')
                ->where('onboarding_submission_files.id', $businessId)
                ->whereColumn('onboarding_submission_files.tenant_id', 'e.tenant_id')->value('e.tenant_id');

        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
