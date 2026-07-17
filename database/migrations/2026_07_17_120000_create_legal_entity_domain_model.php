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
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('The tenant-local Legal Entity domain migration requires PostgreSQL.');
        }

        if (DB::table('customers')->exists()
            || DB::table('sites')->exists()
            || DB::table('employees')->exists()) {
            throw new RuntimeException(
                'This breaking migration cannot migrate existing customers, sites, or employees. '
                .'Empty these tables explicitly before deploying the tenant-local Legal Entity domain model.'
            );
        }

        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'legal_entities_tenant_id_id_unique');
            $table->index(['tenant_id', 'is_active'], 'legal_entities_tenant_active_index');
        });

        Schema::create('establishments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->uuid('legal_entity_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'establishments_tenant_id_id_unique');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'id'],
                'establishments_tenant_legal_entity_id_unique'
            );
            $table->foreign(
                ['tenant_id', 'legal_entity_id'],
                'establishments_tenant_legal_entity_foreign'
            )->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'is_active'], 'establishments_tenant_legal_active_index');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign('customers_tenant_legal_entity_fk');
            $table->dropIndex('idx_customers_tenant_legal_entity');
            $table->dropColumn(['contact', 'notes', 'metadata']);

            $table->unique(['tenant_id', 'id'], 'customers_tenant_id_id_unique');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'id'],
                'customers_tenant_legal_entity_id_unique'
            );
            $table->foreign(
                ['tenant_id', 'legal_entity_id'],
                'customers_tenant_legal_entity_foreign'
            )->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id'], 'idx_customers_tenant_legal_entity');
        });

        Schema::create('customer_establishments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->uuid('legal_entity_id');
            $table->uuid('customer_id');
            $table->uuid('establishment_id');
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(
                ['tenant_id', 'legal_entity_id', 'customer_id'],
                'customer_establishments_tenant_customer_foreign'
            )->references(['tenant_id', 'legal_entity_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->foreign(
                ['tenant_id', 'legal_entity_id', 'establishment_id'],
                'customer_establishments_tenant_establishment_foreign'
            )->references(['tenant_id', 'legal_entity_id', 'id'])->on('establishments')->restrictOnDelete();
            $table->unique(
                ['tenant_id', 'customer_id', 'establishment_id'],
                'customer_establishments_tenant_customer_establishment_unique'
            );
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign(['organizational_unit_id']);
            $table->dropColumn('organizational_unit_id');
            $table->uuid('legal_entity_id');
            $table->uuid('establishment_id');

            $table->foreign(
                ['tenant_id', 'legal_entity_id', 'customer_id'],
                'sites_tenant_legal_entity_customer_foreign'
            )->references(['tenant_id', 'legal_entity_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'legal_entity_id', 'establishment_id'],
                'sites_tenant_legal_entity_establishment_foreign'
            )->references(['tenant_id', 'legal_entity_id', 'id'])->on('establishments')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'customer_id', 'establishment_id'],
                'sites_tenant_customer_establishment_foreign'
            )->references(['tenant_id', 'customer_id', 'establishment_id'])
                ->on('customer_establishments')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id'], 'sites_tenant_legal_entity_index');
            $table->index(['tenant_id', 'establishment_id'], 'sites_tenant_establishment_index');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['organizational_unit_id']);
            $table->dropColumn('organizational_unit_id');
            $table->uuid('legal_entity_id');
            $table->uuid('establishment_id');

            $table->foreign(
                ['tenant_id', 'legal_entity_id', 'establishment_id'],
                'employees_tenant_legal_entity_establishment_foreign'
            )->references(['tenant_id', 'legal_entity_id', 'id'])->on('establishments')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id'], 'employees_tenant_legal_entity_index');
            $table->index(['tenant_id', 'establishment_id'], 'employees_tenant_establishment_index');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The tenant-local Legal Entity domain migration is intentionally irreversible.');
    }
};
