<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users');

            // Document Metadata
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('document_type', [
                'contract',              // Arbeitsvertrag
                'id_document',           // Personalausweis
                'certificate',           // Certificate (if not linked to specific qualification)
                'banking_details',       // Bankverbindung
                'medical_certificate',   // Gesundheitszeugnis
                'work_permit',           // Arbeitserlaubnis
                'background_check',      // Führungszeugnis
                'other',
            ]);

            // File Storage
            $table->string('file_path'); // Path in storage (e.g., "employees/{employee_id}/documents/{filename}")
            $table->string('file_name'); // Original filename
            $table->string('mime_type'); // application/pdf, image/jpeg, etc.
            $table->unsignedBigInteger('file_size'); // Bytes

            // Expiry (for time-limited documents)
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['valid', 'expiring_soon', 'expired'])->default('valid');

            // Visibility
            $table->boolean('visible_to_employee')->default(false); // Can employee see this?

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['employee_id', 'document_type']);
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
