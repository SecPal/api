<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();

            // Encrypted fields with blind indexes
            $table->text('title_enc');
            $table->string('title_idx', 64);
            $table->text('username_enc')->nullable();
            $table->text('password_enc')->nullable();
            $table->text('url_enc')->nullable();
            $table->text('notes_enc')->nullable();

            // Metadata
            $table->json('tags')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('version')->default(1);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['owner_id', 'created_at']);
            $table->index('title_idx');
            $table->index('expires_at');
        });

        // Add tsvector column AFTER table creation
        DB::statement('ALTER TABLE secrets ADD COLUMN notes_tsv tsvector');

        // Create GIN index for full-text search
        DB::statement('CREATE INDEX idx_secrets_notes_tsv ON secrets USING GIN(notes_tsv)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
