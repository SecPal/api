<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Repositories;

use App\Models\Person;
use App\Support\BlindIndex;
use App\Support\KeyStore;
use Illuminate\Support\Facades\Log;

/**
 * Repository for Person model with encrypted field handling.
 *
 * SECURITY NOTES:
 * - Uses blind indexes for searches (no LIKE on encrypted fields)
 * - Normalizes search inputs before HMAC generation
 * - Tenant-scoped queries enforced
 * - No plaintext in query logs
 */
class PersonRepository
{
    public function __construct(
        private KeyStore $keyStore
    ) {}

    /**
     * Find person by email using blind index.
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  string  $email  Email address (will be normalized)
     */
    public function findByEmail(string $tenantId, string $email): ?Person
    {
        // Generate blind index for search
        $idxKey = $this->keyStore->unwrapIdxKeyForTenant($tenantId);
        $normalized = BlindIndex::normEmail($email);
        $emailIdx = BlindIndex::hmac($normalized, $idxKey);

        // Query using blind index (binary-safe comparison)
        return Person::where('tenant_id', $tenantId)
            ->where('email_idx', $emailIdx)
            ->first();
    }

    /**
     * Find person by phone using blind index.
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  string  $phone  Phone number (will be normalized)
     */
    public function findByPhone(string $tenantId, string $phone): ?Person
    {
        // Generate blind index for search
        $idxKey = $this->keyStore->unwrapIdxKeyForTenant($tenantId);
        $normalized = BlindIndex::normPhone($phone);
        $phoneIdx = BlindIndex::hmac($normalized, $idxKey);

        // Query using blind index
        return Person::where('tenant_id', $tenantId)
            ->where('phone_idx', $phoneIdx)
            ->first();
    }

    /**
     * Find person by UUID.
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  string  $personId  Person UUID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findById(string $tenantId, string $personId): Person
    {
        return Person::where('tenant_id', $tenantId)
            ->where('id', $personId)
            ->firstOrFail();
    }

    /**
     * Delete person by UUID.
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  string  $personId  Person UUID
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(string $tenantId, string $personId): void
    {
        $person = $this->findById($tenantId, $personId);
        $person->delete();
    }

    /**
     * Create or update person by email (upsert logic).
     *
     * Automatically generates blind indexes for searchable fields.
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  array  $data  Person data (email, phone, address, note)
     */
    public function createOrUpdate(string $tenantId, array $data): Person
    {
        $email = $data['email'] ?? null;

        if (! $email) {
            throw new \InvalidArgumentException('Email is required');
        }

        // Try to find existing person by email
        $person = $this->findByEmail($tenantId, $email);

        if (! $person) {
            // Create new person
            $person = new Person;
            $person->tenant_id = $tenantId;

            Log::info('Creating new person', [
                'tenant_id' => $tenantId,
                'has_email' => ! empty($data['email']),
                'has_phone' => ! empty($data['phone']),
            ]);
        } else {
            Log::info('Updating existing person', [
                'tenant_id' => $tenantId,
                'person_id' => $person->id,
            ]);
        }

        // Set plaintext fields (triggers automatic encryption and index generation)
        if (isset($data['email'])) {
            $person->email_plain = $data['email'];
        }

        if (isset($data['phone'])) {
            $person->phone_plain = $data['phone'];
        }

        if (isset($data['address'])) {
            $person->address_plain = $data['address'];
        }

        if (isset($data['note'])) {
            $person->note_plain = $data['note'];
        }

        $person->save();

        return $person;
    }

    /**
     * Search persons by partial email match using FTS on note field.
     *
     * NOTE: This is for demonstration only. In production, consider if FTS
     * on encrypted fields is acceptable (note_enc is decrypted for FTS).
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  string  $searchTerm  Search term
     */
    public function searchByNote(string $tenantId, string $searchTerm): \Illuminate\Database\Eloquent\Collection
    {
        // Use PostgreSQL full-text search on tsvector column
        return Person::where('tenant_id', $tenantId)
            ->whereRaw("note_tsv @@ plainto_tsquery('english', ?)", [$searchTerm])
            ->get();
    }

    /**
     * Get all persons for a tenant (paginated).
     *
     * @param  string  $tenantId  Tenant UUID
     * @param  int  $perPage  Items per page
     */
    public function getAllForTenant(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Person::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
