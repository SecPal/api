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
 * Create the object_areas table for segmenting large objects.
 *
 * Large objects like airports, industrial sites, or shopping centers need
 * to be divided into areas with optional separate guard books. This enables
 * fine-grained access control and area-specific reporting.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - object_id links to parent object with cascading deletes
 * - requires_separate_guard_book flag controls guard book assignment
 * - GPS boundaries for geofencing/patrol verification (optional)
 * - Soft deletes to preserve guard book history
 *
 * Examples:
 * - Airport: "Terminal 1", "Terminal 2", "Vorfeld", "Parkhaus P1"
 * - Shopping Center: "Erdgeschoss", "1. OG", "Tiefgarage", "Außenbereich"
 * - Industrial Site: "Halle A", "Halle B", "Verwaltung", "Außenlager"
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
        Schema::create('object_areas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->foreignUuid('object_id')
                ->constrained('objects')
                ->cascadeOnDelete();

            // Core area data
            $table->string('name'); // "Haupteingang", "Lager Halle 3", "Parkplatz Nord"
            $table->text('description')->nullable();

            // Does this area require a separate guard book?
            // If true, events in this area are recorded in an area-specific guard book
            // If false, events are recorded in the object-wide guard book
            $table->boolean('requires_separate_guard_book')->default(false);

            // Optional geofencing boundaries for patrol verification
            // Polygon coordinates: [{"lat": 52.520, "lon": 13.405}, {...}, ...]
            $table->jsonb('gps_boundaries')->nullable();

            // Flexible metadata for custom attributes
            // Example: { "floor": 2, "access_level": "restricted", "patrol_interval_minutes": 60 }
            $table->jsonb('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Optimize common queries
            $table->index(['tenant_id', 'object_id'], 'idx_object_areas_tenant_object');

            $table->comment('Segmentation of large objects into areas with optional separate guard books');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('object_areas');
    }
};
