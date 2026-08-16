<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repositories;

use App\Models\Person;
use App\Models\TenantKey;
use App\Traits\NormalizesPersonFields;

/**
 * PersonRepository provides business logic for Person operations.
 *
 * Handles blind index search and creates/updates with tenant isolation.
 */
class PersonRepository
{
    use NormalizesPersonFields;

    /**
     * Find a Person by email using blind index search.
     *
     * @param  int  $tenantId  Tenant ID for isolation
     * @param  string  $email  Plaintext email to search
     * @return Person|null Person if found, null otherwise
     */
    public function findByEmail(int $tenantId, string $email): ?Person
    {
        $tenantKey = TenantKey::findOrFail($tenantId);
        $normalized = $this->normalizeEmail($email);
        $emailIdx = $tenantKey->generateBlindIndex($normalized);

        // email_idx is stored as base64 string (no cast), so encode for comparison
        return Person::where('tenant_id', $tenantId)
            ->where('email_idx', base64_encode($emailIdx))
            ->first();
    }

    /**
     * Find a Person by phone using blind index search.
     *
     * @param  int  $tenantId  Tenant ID for isolation
     * @param  string  $phone  Plaintext phone to search
     * @return Person|null Person if found, null otherwise
     */
    public function findByPhone(int $tenantId, string $phone): ?Person
    {
        $tenantKey = TenantKey::findOrFail($tenantId);
        $normalized = $this->normalizePhone($phone);
        $phoneIdx = $tenantKey->generateBlindIndex($normalized);

        // phone_idx is stored as base64 string (no cast), so encode for comparison
        return Person::where('tenant_id', $tenantId)
            ->where('phone_idx', base64_encode($phoneIdx))
            ->first();
    }

    /**
     * Create or update a Person by email.
     *
     * If a Person with the given email exists, update it. Otherwise, create new.
     *
     * @param  int  $tenantId  Tenant ID for isolation
     * @param  array<string, mixed>  $attributes  Attributes including email_plain, phone_plain, note_plain
     * @return Person The created or updated Person
     *
     * @throws \InvalidArgumentException if email_plain is missing or not a string
     */
    public function createOrUpdate(int $tenantId, array $attributes): Person
    {
        $email = $attributes['email_plain'] ?? null;

        if ($email === null) {
            throw new \InvalidArgumentException('email_plain is required');
        }
        if (! is_string($email)) {
            throw new \InvalidArgumentException('email_plain must be a string');
        }

        $person = $this->findByEmail($tenantId, $email);

        if ($person) {
            // Update existing
            if (isset($attributes['phone_plain']) && is_string($attributes['phone_plain'])) {
                $person->phone_plain = $attributes['phone_plain'];
            }
            if (array_key_exists('note_plain', $attributes) && (is_string($attributes['note_plain']) || $attributes['note_plain'] === null)) {
                $person->note_plain = $attributes['note_plain'];
            }
            $person->save();
        } else {
            // Create new
            $person = new Person;
            $person->tenant_id = $tenantId;
            $person->email_plain = $email;
            if (isset($attributes['phone_plain']) && is_string($attributes['phone_plain'])) {
                $person->phone_plain = $attributes['phone_plain'];
            }
            if (array_key_exists('note_plain', $attributes) && (is_string($attributes['note_plain']) || $attributes['note_plain'] === null)) {
                $person->note_plain = $attributes['note_plain'];
            }
            $person->save();
        }

        return $person;
    }
}
