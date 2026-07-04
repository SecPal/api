<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

namespace App\Observers;

use App\Models\Person;
use App\Models\TenantKey;
use App\Traits\NormalizesPersonFields;

/**
 * PersonObserver automatically computes blind indexes for encrypted fields.
 *
 * When a Person is created or updated, this observer:
 * 1. Normalizes plaintext values (email_plain, phone_plain)
 * 2. Generates HMAC-SHA256 blind indexes using the tenant's idx_key
 * 3. Stores indexes in *_idx VARCHAR columns for equality search
 */
class PersonObserver
{
    use NormalizesPersonFields;

    /**
     * Handle the Person "creating" event.
     *
     * Compute blind indexes before initial save.
     */
    public function creating(Person $person): void
    {
        $this->updateBlindIndexes($person);
    }

    /**
     * Handle the Person "updating" event.
     *
     * Recompute blind indexes if plaintext fields changed.
     */
    public function updating(Person $person): void
    {
        // Only recompute if transient plaintext attributes are set
        if ($person->email_plain !== null || $person->phone_plain !== null) {
            $this->updateBlindIndexes($person);
        }
    }

    /**
     * Update blind indexes for email and phone.
     */
    private function updateBlindIndexes(Person $person): void
    {
        $tenantKey = TenantKey::findOrFail($person->tenant_id);

        // Compute email_idx if email_plain is set
        if ($person->email_plain !== null) {
            $normalized = $this->normalizeEmail($person->email_plain);
            $rawIdx = $tenantKey->generateBlindIndex($normalized);
            // Store as base64 string directly (no cast on *_idx columns)
            $person->email_idx = base64_encode($rawIdx);
        }

        // Compute phone_idx if phone_plain is set
        if ($person->phone_plain !== null) {
            $normalized = $this->normalizePhone($person->phone_plain);
            $rawIdx = $tenantKey->generateBlindIndex($normalized);
            // Store as base64 string directly (no cast on *_idx columns)
            $person->phone_idx = base64_encode($rawIdx);
        }
    }
}
