<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repositories;

use App\Models\Person;
use App\Models\TenantKey;

/**
 * PersonRepository provides business logic for Person operations.
 *
 * Handles blind index search and creates/updates with tenant isolation.
 */
class PersonRepository
{
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

        return Person::where('tenant_id', $tenantId)
            ->where('email_idx', $emailIdx)
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

        return Person::where('tenant_id', $tenantId)
            ->where('phone_idx', $phoneIdx)
            ->first();
    }

    /**
     * Create or update a Person by email.
     *
     * If a Person with the given email exists, update it. Otherwise, create new.
     *
     * @param  int  $tenantId  Tenant ID for isolation
     * @param  array<string, mixed>  $attributes  Attributes including email_plain, phone_plain, note_enc
     * @return Person The created or updated Person
     *
     * @throws \InvalidArgumentException if email_plain is missing or not a string
     */
    public function createOrUpdate(int $tenantId, array $attributes): Person
    {
        $email = $attributes['email_plain'] ?? null;

        if (! is_string($email)) {
            throw new \InvalidArgumentException('email_plain is required and must be a string');
        }

        $person = $this->findByEmail($tenantId, $email);

        if ($person) {
            // Update existing
            if (isset($attributes['phone_plain']) && is_string($attributes['phone_plain'])) {
                $person->phone_plain = $attributes['phone_plain'];
            }
            if (isset($attributes['note_enc']) && is_string($attributes['note_enc'])) {
                $person->note_enc = $attributes['note_enc'];
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
            if (isset($attributes['note_enc']) && is_string($attributes['note_enc'])) {
                $person->note_enc = $attributes['note_enc'];
            }
            $person->save();
        }

        return $person;
    }

    /**
     * Normalize email for blind index generation.
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Normalize phone for blind index generation.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?: '';
    }
}
