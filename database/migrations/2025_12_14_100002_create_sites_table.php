<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the sites table for physical locations where services are provided.
 *
 * Sites represent the physical locations (objects/facilities) where security
 * services are provided. Each site belongs to exactly one customer and is
 * managed by one internal organizational unit.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - Foreign keys to customers and organizational_units
 * - Auto-generated site_number for unique identification
 * - Type field: permanent (ongoing) or temporary (event-based)
 * - JSON address with GPS coordinates for geofencing
 * - Validity period for temporary sites
 * - Soft deletes to preserve historical data
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#308 Database migrations for customers and sites
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Relationships: Customer that owns this site
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Internal org unit responsible for this site
            $table->foreignUuid('organizational_unit_id')
                ->constrained('organizational_units');

            // Identification
            $table->string('site_number')->comment('Auto-generated unique ID, e.g., OBJ-2025-001');
            $table->string('name')->comment('Site name, e.g., "Airport Terminal 1"');

            // Type
            $table->enum('type', ['permanent', 'temporary'])
                ->default('permanent')
                ->comment('permanent: ongoing contract, temporary: event-based');

            // Location (JSON structure)
            // Example: {"street": "Flughafenstr. 1", "city": "Berlin", "postal_code": "12345", "country": "DE", "lat": 52.5200, "lng": 13.4050}
            $table->jsonb('address')->comment('Physical address with GPS coordinates');

            // On-site Contact (JSON structure)
            // Example: {"name": "Site Manager", "email": "manager@site.com", "phone": "+49 123 456789", "position": "Facility Manager"}
            $table->jsonb('contact')->nullable()->comment('On-site contact person');

            // Operational Info
            $table->text('access_instructions')->nullable()->comment('How to access the site');
            $table->text('notes')->nullable()->comment('Internal notes');
            $table->jsonb('metadata')->nullable()->comment('Extensible metadata');

            // Status & Validity
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable()->comment('Contract start date (for temporary sites)');
            $table->date('valid_until')->nullable()->comment('Contract end date (for temporary sites)');

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->unique(['tenant_id', 'site_number'], 'unique_tenant_site_number');
            $table->index(['tenant_id', 'customer_id'], 'idx_sites_tenant_customer');
            $table->index(['tenant_id', 'organizational_unit_id'], 'idx_sites_tenant_org_unit');
            $table->index(['tenant_id', 'type', 'is_active'], 'idx_sites_tenant_type_active');

            $table->comment('Physical locations where security services are provided');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
