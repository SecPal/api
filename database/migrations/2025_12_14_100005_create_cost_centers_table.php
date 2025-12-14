<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the cost_centers table for optional billing/accounting integration.
 *
 * Cost centers are optional and used by companies that need detailed
 * billing/accounting integration at the site level. Not all companies
 * use cost centers, so this table may remain empty for some tenants.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - Belongs to a site (sites can have multiple cost centers)
 * - Code field for customer's internal accounting number
 * - Unique constraint: (site_id, code) - same code can exist on different sites
 * - Soft deletes to preserve historical references
 * - activity_type field for future tariff mapping
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#309 Database migrations for assignments and cost centers
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Relationship to Site
            $table->foreignUuid('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();

            // Identification
            $table->string('code', 50)->comment('Cost center code, e.g., KST-001 or customer internal number');
            $table->string('name', 255)->comment('Descriptive name, e.g., "Reception Duty"');

            // Activity Type (for future tariff mapping)
            $table->string('activity_type', 100)->nullable()->comment('Type of activity performed');

            // Description
            $table->text('description')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->unique(['site_id', 'code'], 'unique_site_cost_center_code');
            $table->index(['tenant_id', 'is_active'], 'idx_cost_centers_tenant_active');
            $table->index('site_id', 'idx_cost_centers_site');

            $table->comment('Optional cost centers for billing/accounting integration at site level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
