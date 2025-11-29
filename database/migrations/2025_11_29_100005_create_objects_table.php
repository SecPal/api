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
 * Create the objects table for physical locations/sites managed by customers.
 *
 * Objects represent the physical locations where security services are provided.
 * Each object belongs to exactly one customer and may have multiple areas
 * with separate guard books.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - customer_id links to the owning customer organization
 * - GPS coordinates stored as jsonb for flexibility and spatial queries
 * - Unique object_number per tenant (not globally unique)
 * - Soft deletes to preserve guard book history
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
        Schema::create('objects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Core object data
            $table->string('object_number'); // Per-tenant unique identifier (e.g., "OBJ-REWE-HH-001")
            $table->string('name'); // "Einkaufszentrum Alexanderplatz", "Flughafen BER Terminal 1"
            $table->text('address'); // Full address for the location

            // GPS coordinates for geofencing and map display
            // Example: { "lat": 52.5200, "lon": 13.4050 }
            $table->jsonb('gps_coordinates')->nullable();

            // Flexible metadata for custom attributes
            // Example: { "floors": 3, "parking_available": true, "emergency_contacts": [...] }
            $table->jsonb('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Object number must be unique per tenant
            $table->unique(['tenant_id', 'object_number'], 'unique_tenant_object_number');

            // Optimize common queries
            $table->index(['tenant_id', 'customer_id'], 'idx_objects_tenant_customer');

            $table->comment('Physical locations/sites where security services are provided');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('objects');
    }
};
