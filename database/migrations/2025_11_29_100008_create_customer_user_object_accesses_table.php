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
 * Create the customer_user_object_accesses table for fine-grained object permissions.
 *
 * This table enables object-level access control for customer users who need
 * access to specific objects without full customer hierarchy access.
 * The allowed_actions jsonb column provides extensible permission control.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - Cascading deletes when user or object is removed
 * - allowed_actions as jsonb enables extensibility without schema changes
 * - Unique constraint prevents duplicate user-object assignments
 *
 * Typical allowed_actions:
 * - "read_guard_book": View guard book entries
 * - "read_reports": View generated reports
 * - "export_reports": Download/export reports as PDF
 * - "view_shifts": See scheduled shifts
 * - "view_incidents": View incident details
 *
 * IMPORTANT: All actions are READ-ONLY - customer users cannot modify data!
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
        Schema::create('customer_user_object_accesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignUuid('object_id')
                ->constrained('objects')
                ->cascadeOnDelete();

            // Allowed actions as jsonb array for extensibility
            // Example: ["read_guard_book", "read_reports", "export_reports"]
            // Default: ["read_guard_book"] - minimum read-only access
            $table->jsonb('allowed_actions')->default('["read_guard_book"]');

            $table->timestamps();

            // Each user can only have one access record per object
            $table->unique(['user_id', 'object_id'], 'unique_user_object_access');

            // Optimize common queries
            $table->index(['tenant_id', 'user_id'], 'idx_customer_user_object_accesses_tenant_user');

            $table->comment('Fine-grained object permissions for customer users - READ-ONLY actions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_user_object_accesses');
    }
};
