<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create activity_log table with SecPal custom extensions.
 *
 * Extends Spatie Laravel Activity Log with:
 * - Tenant and organizational unit isolation
 * - Request metadata (IP, user agent)
 * - Hash chain for tamper detection
 * - Merkle tree for batch verification
 * - OpenTimestamp integration for blockchain anchoring
 * - Retention metadata and soft deletes
 *
 * @see ADR-010 Activity Logging & Audit Trail Strategy
 * @see Issue #386 PR-1: Install Spatie Activity Log & extend database schema
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            // Primary key (bigint auto-increment)
            $table->id();

            // 🔐 Tenant + Organizational Scope Isolation
            $table->foreignId('tenant_id')
                ->constrained('tenant_keys')
                ->cascadeOnDelete();
            $table->foreignUuid('organizational_unit_id')->nullable()
                ->constrained('organizational_units')->nullOnDelete();

            // 📝 Spatie Activity Log Core Fields
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();

            // 📊 Request Metadata
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            // 🔗 Hash Chain (real-time tamper detection)
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64)->index();

            // 🌳 Merkle Tree (batch verification)
            $table->string('merkle_root', 64)->nullable();
            $table->bigInteger('merkle_batch_id')->nullable()->index();
            $table->json('merkle_proof')->nullable();

            // ⏱️ OpenTimestamp (Bitcoin anchoring)
            $table->text('ots_proof')->nullable();
            $table->timestamp('ots_submitted_at')->nullable();
            $table->timestamp('ots_confirmed_at')->nullable();

            // 📅 Retention Metadata
            $table->boolean('is_orphaned_genesis')->default(false);
            $table->text('orphaned_reason')->nullable();
            $table->timestamp('orphaned_at')->nullable();

            // 🗑️ Soft Delete Support
            $table->softDeletes();

            // ⏰ Timestamps
            $table->timestamps();

            // 📇 Performance Indexes
            $table->index(['tenant_id', 'created_at']);
            $table->index(['organizational_unit_id', 'created_at']);
            $table->index(['log_name', 'created_at']);
            $table->index(['tenant_id', 'log_name', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
