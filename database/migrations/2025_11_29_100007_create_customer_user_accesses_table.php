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
 * Create the customer_user_accesses table for customer user RBAC integration.
 *
 * This table maps external customer users (Client role) to their access scopes
 * within customer hierarchies. Customer users have READ-ONLY access to their
 * assigned customers and optionally all descendants.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - Cascading deletes when user or customer is removed
 * - access_level enum: corporate_wide, regional, local
 * - include_descendants flag enables hierarchical access inheritance
 * - Unique constraint prevents duplicate user-customer assignments
 *
 * Access patterns:
 * - corporate_wide + include_descendants: Access all customers in hierarchy
 * - regional + include_descendants: Access regional customer and local children
 * - local: Access only the specific local customer
 *
 * IMPORTANT: Customer users have READ-ONLY access (no create/update/delete)!
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
        Schema::create('customer_user_accesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Access level determines the scope of access:
            // corporate_wide: Access to entire customer organization (e.g., Rewe Corporate Security Manager)
            // regional: Access to regional division (e.g., Rewe Regional Coordinator Nord)
            // local: Access to single location (e.g., Store Manager Hamburg Altona)
            $table->enum('access_level', ['corporate_wide', 'regional', 'local'])
                ->default('local');

            // Include descendants in scope?
            // When true: Corporate user sees all regional/local customers below
            // When false: Access limited to the exact customer specified
            $table->boolean('include_descendants')->default(true);

            $table->timestamps();

            // Each user can only have one access record per customer
            $table->unique(['user_id', 'customer_id'], 'unique_user_customer_access');

            // Optimize common queries
            $table->index(['tenant_id', 'user_id'], 'idx_customer_user_accesses_tenant_user');

            $table->comment('Customer user access scopes - READ-ONLY access to customer hierarchy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_user_accesses');
    }
};
