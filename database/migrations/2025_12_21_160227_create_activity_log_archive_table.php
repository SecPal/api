<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create activity_log_archive table for GDPR-compliant retention.
 *
 * This table stores ONLY cryptographic hashes and metadata for archived logs.
 * It explicitly EXCLUDES all personal data (properties, subject, causer, description)
 * to comply with GDPR data minimization requirements while maintaining hash chain
 * integrity for legal verification.
 *
 * Archived logs can still be verified for tampering even after personal data deletion.
 *
 * @see ADR-010 Section 5: Retention & Archiving
 * @see Issue #386 PR-1: Install Spatie Activity Log & extend database schema
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_log_archive', function (Blueprint $table) {
            // Original Activity Log ID (preserved for chain continuity)
            $table->uuid('id')->primary();

            // 🔐 Tenant Isolation (required for scoped access)
            $table->foreignId('tenant_id')
                ->constrained('tenant_keys')
                ->cascadeOnDelete();

            // 📝 Minimal Metadata (log type only)
            $table->string('log_name')->nullable();

            // ⏰ Timestamp (for retention policy enforcement)
            $table->timestamp('created_at')->index();

            // 🔗 Hash Chain (for integrity verification)
            $table->string('event_hash', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();

            // 🌳 Merkle Tree (for batch verification)
            $table->string('merkle_root', 64)->nullable();
            $table->uuid('merkle_batch_id')->nullable();

            // Performance: Composite index for tenant-scoped time-range queries
            $table->index(['tenant_id', 'created_at']);

            // NO properties, subject, causer, description (GDPR compliance!)
            // NO timestamps() - immutable archive, only created_at needed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log_archive');
    }
};
