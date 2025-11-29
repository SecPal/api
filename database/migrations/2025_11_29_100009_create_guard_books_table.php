<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create guard_books table for continuous event stream containers.
 *
 * Guard books are NOT closed physical books but continuous event streams.
 * Reports can be generated from events for any time period on-demand.
 *
 * Key Design Decisions (from ADR-007):
 * - XOR constraint: object_id OR object_area_id, but not both (mutually exclusive)
 * - is_active flag for archiving without deletion
 * - Soft deletes for data preservation
 * - Tenant isolation via tenant_id
 *
 * @see Issue #233: Guard Books Event Stream Implementation
 * @see ADR-007: Organizational Structure & Hierarchies
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guard_books', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys');

            // EITHER entire object OR specific area (mutually exclusive)
            // The XOR constraint is enforced via CHECK constraint below
            $table->foreignUuid('object_id')
                ->nullable()
                ->references('id')
                ->on('objects')
                ->cascadeOnDelete();

            $table->foreignUuid('object_area_id')
                ->nullable()
                ->references('id')
                ->on('object_areas')
                ->cascadeOnDelete();

            $table->string('title'); // "Wachbuch Haupteingang", "Wachbuch Gesamtobjekt"
            $table->text('description')->nullable();

            // Guard books are continuous, not "closed"
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance-critical queries
            $table->index(['tenant_id', 'object_id'], 'guard_books_tenant_object_idx');
            $table->index(['tenant_id', 'object_area_id'], 'guard_books_tenant_area_idx');
            $table->index(['tenant_id', 'is_active'], 'guard_books_tenant_active_idx');
        });

        // PostgreSQL CHECK constraint: XOR - exactly one of object_id or object_area_id must be set
        // This prevents:
        // 1. Both being set (guard book can't belong to both object AND area)
        // 2. Neither being set (guard book must belong to something)
        DB::statement('
            ALTER TABLE guard_books
            ADD CONSTRAINT guard_books_object_xor_area
            CHECK (
                (object_id IS NOT NULL AND object_area_id IS NULL) OR
                (object_id IS NULL AND object_area_id IS NOT NULL)
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guard_books');
    }
};
