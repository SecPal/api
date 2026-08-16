<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->nullable()->constrained('tenant_keys')->cascadeOnDelete();
            // NULL tenant_id = system-wide predefined qualification
            // Non-null tenant_id = custom qualification for specific tenant

            $table->string('name'); // "§34a Sachkundeprüfung", "Erste Hilfe", etc.
            $table->text('description')->nullable();

            // Category for grouping
            $table->enum('category', [
                'bewachv_34a',        // §34a related (Sachkunde)
                'first_aid',          // First aid / medical
                'fire_safety',        // Fire protection
                'safety_officer',     // Safety roles
                'specialized',        // Dogs, weapons, intervention
                'education',          // IHK certifications (GSSK, Fachkraft, Meister)
                'custom',             // Tenant-specific
            ])->default('custom');

            // Validity
            $table->boolean('requires_renewal')->default(false);
            $table->integer('renewal_period_months')->nullable(); // 24 for first aid
            $table->boolean('is_mandatory')->default(false); // Required for all employees?

            // System vs. Custom
            $table->boolean('is_system_qualification')->default(false);
            // System qualifications: Cannot be deleted, editable only via privileged management flows
            // Custom qualifications: Tenant-specific, fully editable

            // Sorting
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['tenant_id', 'category']);
            $table->index('is_system_qualification');

            // Unique constraint: Qualification names must be unique per tenant
            // (system qualifications have tenant_id = NULL)
            $table->unique(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qualifications');
    }
};
