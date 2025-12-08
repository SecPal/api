<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Observers;

use App\Models\Employee;
use App\Models\TenantKey;

/**
 * EmployeeObserver automatically computes blind indexes for encrypted fields.
 *
 * When an Employee is created or updated, this observer:
 * 1. Generates HMAC-SHA256 blind indexes using the tenant's idx_key
 * 2. Stores indexes in *_idx VARCHAR columns for equality search
 */
class EmployeeObserver
{
    /**
     * Handle the Employee "creating" event.
     *
     * Compute blind indexes before initial save.
     */
    public function creating(Employee $employee): void
    {
        $this->updateBlindIndexes($employee);
    }

    /**
     * Handle the Employee "updating" event.
     *
     * Recompute blind indexes if encrypted fields changed.
     */
    public function updating(Employee $employee): void
    {
        // Recompute indexes if encrypted fields are dirty
        if ($employee->isDirty(['first_name_enc', 'last_name_enc', 'date_of_birth_enc'])) {
            $this->updateBlindIndexes($employee);
        }
    }

    /**
     * Update blind indexes for first_name, last_name, date_of_birth.
     */
    private function updateBlindIndexes(Employee $employee): void
    {
        $tenantKey = TenantKey::findOrFail($employee->tenant_id);

        // Compute first_name_idx if first_name_enc is set
        if ($employee->first_name_enc !== null) {
            // Extract plaintext from encrypted JSON (format: {"ciphertext":"...","nonce":"..."})
            $decrypted = $employee->first_name; // Accessor handles decryption via Cast
            $rawIdx = $tenantKey->generateBlindIndex(mb_strtolower($decrypted));
            $employee->first_name_idx = base64_encode($rawIdx);
        }

        // Compute last_name_idx if last_name_enc is set
        if ($employee->last_name_enc !== null) {
            $decrypted = $employee->last_name; // Accessor handles decryption via Cast
            $rawIdx = $tenantKey->generateBlindIndex(mb_strtolower($decrypted));
            $employee->last_name_idx = base64_encode($rawIdx);
        }

        // Compute date_of_birth_idx if date_of_birth_enc is set
        if ($employee->date_of_birth_enc !== null) {
            $decrypted = $employee->date_of_birth; // Accessor handles decryption via Cast
            if ($decrypted !== null) {
                $rawIdx = $tenantKey->generateBlindIndex($decrypted); // No lowercasing for dates
                $employee->date_of_birth_idx = base64_encode($rawIdx);
            }
        }
    }
}
