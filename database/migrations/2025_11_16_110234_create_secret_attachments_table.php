<?php

// SPDX-FileCopyrightText: 2025 SecPal <https://github.com/SecPal>
//
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
        Schema::create('secret_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('secret_id')->constrained('secrets')->cascadeOnDelete();

            // Foreign key to tenant_keys table
            // REQUIRED: EncryptedWithDek cast needs tenant_id to load the correct DEK for encryption/decryption
            // The tenant_id MUST be set before accessing any encrypted fields (filename_enc)
            $table->foreignId('tenant_id')->constrained('tenant_keys');

            // File metadata (encrypted)
            $table->text('filename_enc'); // Original filename (encrypted)
            $table->unsignedBigInteger('file_size'); // Original size in bytes
            $table->string('mime_type', 255); // MIME type
            $table->text('storage_path'); // Path to encrypted blob
            $table->string('checksum_sha256', 64)->nullable(); // File integrity

            // Audit
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();

            // Indexes
            $table->index('secret_id');
            $table->index(['secret_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secret_attachments');
    }
};
