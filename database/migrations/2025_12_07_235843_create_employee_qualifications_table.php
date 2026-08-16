<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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
        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('qualification_id')->constrained('qualifications')->cascadeOnDelete();

            // Dates
            $table->date('obtained_date');
            $table->date('expiry_date')->nullable();

            // Certificate Details
            $table->string('certificate_number')->nullable();
            $table->string('issuing_authority')->nullable(); // "IHK Berlin", "DRK München"
            $table->text('notes')->nullable();

            // Document Storage
            $table->string('document_path')->nullable(); // Path to uploaded PDF/image

            // Status (auto-calculated based on expiry_date)
            $table->enum('status', ['valid', 'expiring_soon', 'expired'])->default('valid');

            // Current qualification flag for quick filtering (allows full history tracking)
            $table->boolean('is_current')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'qualification_id', 'is_current']); // Fast query for current qualification
            $table->index('expiry_date');

            // Note: No unique constraint to allow full history tracking of qualification renewals
            // Use is_current=true to filter for the latest/active qualification per employee
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_qualifications');
    }
};
