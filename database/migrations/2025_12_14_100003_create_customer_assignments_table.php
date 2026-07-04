<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the customer_assignments table for flexible user-to-customer role assignments.
 *
 * This table enables tenant-specific role names (e.g., "Key Account", "Sales Representative")
 * without requiring schema changes. Each assignment can have a validity period for
 * historical tracking and temporary assignments.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - Flexible role field (string) allows tenant-specific terminology
 * - Unique constraint prevents duplicate user+role assignments to same customer
 * - Valid_from/valid_until for temporal assignments and historical tracking
 * - Soft deletes NOT used (assignments are historical records)
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
        Schema::create('customer_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Relationships
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Flexible Role (tenant-specific terminology)
            // Examples: "Key Account Manager", "Sales Representative", "Support Contact"
            $table->string('role', 100)->comment('Flexible role name, tenant-specific');

            // Validity Period (for historical tracking and temporary assignments)
            $table->date('valid_from')->nullable()->comment('When assignment starts');
            $table->date('valid_until')->nullable()->comment('When assignment ends (null = indefinite)');

            // Notes
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->unique(['customer_id', 'user_id', 'role'], 'unique_customer_user_role');
            $table->index(['tenant_id', 'user_id'], 'idx_customer_assignments_tenant_user');
            $table->index(['tenant_id', 'role'], 'idx_customer_assignments_tenant_role');
            $table->index(['customer_id', 'valid_from', 'valid_until'], 'idx_customer_assignments_validity');

            $table->comment('Flexible user-to-customer role assignments with temporal tracking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_assignments');
    }
};
