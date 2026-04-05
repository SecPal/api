<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_submission_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('onboarding_form_submission_id')->constrained('onboarding_form_submissions')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->enum('document_type', ['contract', 'id_document', 'banking_details']);
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['onboarding_form_submission_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_submission_files');
    }
};
