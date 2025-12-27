<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix ActivityArchive ID type to match Activity.id (bigint).
 *
 * Original migration incorrectly used UUID for archive ID,
 * but archives must preserve original Activity log IDs (bigint)
 * for hash chain continuity.
 *
 * @see Issue #392 PR-8: Activity Archive & Retention Policies
 * @see ADR-010 Section 5: Retention & Archiving
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop and recreate with correct ID type (table should be empty during dev)
        Schema::dropIfExists('activity_log_archive');

        Schema::create('activity_log_archive', function (Blueprint $table) {
            // Original Activity Log ID (bigint, not UUID)
            $table->id();

            // 🔐 Tenant Isolation
            $table->foreignId('tenant_id')
                ->constrained('tenant_keys')
                ->cascadeOnDelete();

            // 📝 Minimal Metadata
            $table->string('log_name')->nullable();

            // ⏰ Timestamp
            $table->timestamp('created_at')->index();

            // 🔗 Hash Chain
            $table->string('event_hash', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();

            // 🌳 Merkle Tree Metadata
            $table->string('merkle_root', 64)->nullable();
            $table->integer('merkle_batch_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original UUID-based table
        Schema::dropIfExists('activity_log_archive');

        Schema::create('activity_log_archive', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')
                ->constrained('tenant_keys')
                ->cascadeOnDelete();
            $table->string('log_name')->nullable();
            $table->timestamp('created_at')->index();
            $table->string('event_hash', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('merkle_root', 64)->nullable();
            $table->integer('merkle_batch_id')->nullable();
        });
    }
};
