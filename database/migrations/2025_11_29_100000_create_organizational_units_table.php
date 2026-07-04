<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create organizational_units table for internal organizational structure.
 *
 * This table represents the security service company's internal hierarchy
 * (Holding → Company → Region → Branch → Division).
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
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            // Flexible type system for internal organization
            $table->enum('type', [
                'holding',     // Konzern/Holding (e.g., "ProSec Holding")
                'company',     // Gesellschaft (e.g., "ProSec Nord GmbH")
                'region',      // Region (e.g., "Region Berlin-Brandenburg")
                'branch',      // Niederlassung (e.g., "Niederlassung Berlin")
                'division',    // Sparte/Abteilung (e.g., "Event Security")
                'department',  // Bereich (e.g., "Operations Team Nord")
                'custom',      // User-defined type
            ]);

            $table->string('name');
            $table->string('custom_type_name')->nullable();
            $table->text('description')->nullable();

            // Flexible metadata (addresses, contact info, etc.)
            $table->jsonb('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'deleted_at']);

            $table->comment('Internal organizational structure of the security service company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizational_units');
    }
};
