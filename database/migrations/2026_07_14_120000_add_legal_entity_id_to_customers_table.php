<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('customers')->exists()) {
            throw new RuntimeException(
                'Cannot add customers.legal_entity_id while customers exist. '
                .'US-001 requires a product-approved deterministic tenant-consistent backfill before rollout.'
            );
        }

        Schema::table('organizational_units', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'unique_organizational_units_tenant_id_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignUuid('legal_entity_id')
                ->after('tenant_id');

            $table->index(['tenant_id', 'legal_entity_id'], 'idx_customers_tenant_legal_entity');

            $table->foreign(['tenant_id', 'legal_entity_id'], 'customers_tenant_legal_entity_fk')
                ->references(['tenant_id', 'id'])
                ->on('organizational_units')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign('customers_tenant_legal_entity_fk');
            $table->dropIndex('idx_customers_tenant_legal_entity');
            $table->dropColumn('legal_entity_id');
        });

        Schema::table('organizational_units', function (Blueprint $table): void {
            $table->dropUnique('unique_organizational_units_tenant_id_id');
        });
    }
};
