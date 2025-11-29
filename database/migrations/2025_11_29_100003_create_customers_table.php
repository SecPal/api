<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the customers table for external customer organizations.
 *
 * This table stores customer hierarchies which are COMPLETELY SEPARATE from
 * internal organizational units. Customers represent external organizations
 * that the security service company manages.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - managed_by_organizational_unit_id links to internal org structure (invisible to customers)
 * - Hierarchical type enum: corporate → regional → local → custom
 * - Soft deletes to preserve referential integrity
 * - jsonb metadata for extensibility without schema changes
 *
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251126-organizational-structure-hierarchy.md
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Which internal organizational unit manages this customer?
            // This is for INTERNAL use only - customer users do NOT see this!
            $table->foreignUuid('managed_by_organizational_unit_id')
                ->nullable()
                ->references('id')->on('organizational_units')
                ->nullOnDelete();

            // Core customer data
            $table->string('name'); // "Rewe Group", "Rewe Region Nord", "Rewe Markt Hamburg"
            $table->string('customer_number')->unique(); // Unique identifier across entire system

            // Customer type determines position in hierarchy
            // corporate: National/international customer (e.g., "Rewe Group")
            // regional: Regional division (e.g., "Rewe Region Nord")
            // local: Single location (e.g., "Rewe Markt Hamburg Altona")
            // custom: User-defined type for special cases
            $table->enum('type', ['corporate', 'regional', 'local', 'custom'])
                ->default('local');

            // Business details
            $table->text('address')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Flexible metadata for custom attributes
            // Example: { "industry": "retail", "contract_start": "2025-01-01", "vat_number": "DE123456789" }
            $table->jsonb('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Optimize common queries
            $table->index(['tenant_id', 'type'], 'idx_customers_tenant_type');
            $table->index(['tenant_id', 'managed_by_organizational_unit_id'], 'idx_customers_tenant_managed_by');

            $table->comment('External customer organizations - independent from internal org structure');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
