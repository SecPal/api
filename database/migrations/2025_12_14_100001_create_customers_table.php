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
 * Create the customers table for external customer organizations.
 *
 * This table stores customer data in a flat, non-hierarchical structure.
 * Customers represent external organizations that the security service
 * company provides services to.
 *
 * Key design decisions:
 * - UUID primary key for distributed systems and security
 * - tenant_id for multi-tenancy isolation
 * - Auto-generated customer_number for unique identification
 * - JSON fields for flexible address and contact data
 * - Soft deletes to preserve historical data
 * - No hierarchical structure (flat customer list)
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
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Identification
            $table->string('customer_number')->comment('Auto-generated unique ID, e.g., KD-2025-001');
            $table->string('name')->comment('Company/Organization name');

            // Billing Information (JSON structure)
            // Example: {"street": "Musterstr. 123", "city": "Berlin", "postal_code": "10115", "country": "DE"}
            $table->jsonb('billing_address')->comment('Structured billing address');

            // Contact Information (JSON structure)
            // Example: {"name": "Max Mustermann", "email": "max@example.com", "phone": "+49 30 12345678", "position": "Facility Manager"}
            $table->jsonb('contact')->nullable()->comment('Primary contact person');

            // Additional Info
            $table->text('notes')->nullable()->comment('Internal notes');
            $table->jsonb('metadata')->nullable()->comment('Extensible metadata for custom attributes');

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->unique(['tenant_id', 'customer_number'], 'unique_tenant_customer_number');
            $table->index(['tenant_id', 'is_active'], 'idx_customers_tenant_active');
            $table->index(['tenant_id', 'name'], 'idx_customers_tenant_name');

            $table->comment('External customer organizations (flat structure)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
