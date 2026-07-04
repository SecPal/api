<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\TenantKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfills tenant_id for all existing users. Assigns them to the first available tenant.
     * This migration is safe for single-tenant deployments (current state).
     * For multi-tenant production, manual data migration might be needed.
     *
     * @see https://github.com/SecPal/api/issues/358
     */
    public function up(): void
    {
        // Get first tenant key ID
        $firstTenantId = TenantKey::oldest('id')->value('id');

        if ($firstTenantId === null) {
            // No tenant keys exist - this is fine for fresh installations
            // Users table might be empty too
            return;
        }

        // Assign all users without tenant_id to first tenant
        DB::table('users')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $firstTenantId]);
    }

    /**
     * Reverse the migrations.
     *
     * Sets all tenant_id values back to NULL.
     * This allows clean rollback to nullable state.
     */
    public function down(): void
    {
        DB::table('users')->update(['tenant_id' => null]);
    }
};
