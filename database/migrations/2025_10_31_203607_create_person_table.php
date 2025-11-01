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
     *
     * Person table with encrypted PII and blind indexes for searchability.
     * - *_enc fields: Encrypted with Laravel's 'encrypted' cast (AES-256-GCM)
     * - *_idx fields: HMAC-SHA256 blind indexes for equality search (BYTEA)
     */
    public function up(): void
    {
        Schema::create('person', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Encrypted fields (plaintext encrypted with AES-256-GCM)
            $table->text('email_enc');
            $table->text('phone_enc')->nullable();
            $table->text('address_enc')->nullable();
            $table->text('note_enc')->nullable();

            // Blind indexes (HMAC-SHA256, stored as BYTEA in PostgreSQL)
            $table->binary('email_idx');
            $table->binary('phone_idx')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // Composite indexes for tenant-scoped searches
            $table->index(['tenant_id', 'email_idx'], 'idx_tenant_email');
            $table->index(['tenant_id', 'phone_idx'], 'idx_tenant_phone');
            $table->index('tenant_id');

            // Foreign key to tenant_keys
            $table->foreign('tenant_id')
                ->references('tenant_id')
                ->on('tenant_keys')
                ->onDelete('cascade');
        });

        // Optional: Full-text search support (PostgreSQL only)
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE person ADD COLUMN note_tsv tsvector');
            DB::statement('CREATE INDEX person_note_tsv_gin ON person USING GIN (note_tsv)');

            DB::statement("
                CREATE OR REPLACE FUNCTION person_note_tsv_trigger() RETURNS trigger AS $$
                BEGIN
                    NEW.note_tsv := to_tsvector('english', COALESCE(NEW.note_enc, ''));
                    RETURN NEW;
                END
                $$ LANGUAGE plpgsql;
            ");

            DB::statement('
                CREATE TRIGGER person_note_tsv_update
                BEFORE INSERT OR UPDATE OF note_enc
                ON person
                FOR EACH ROW
                EXECUTE FUNCTION person_note_tsv_trigger();
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS person_note_tsv_update ON person');
            DB::statement('DROP FUNCTION IF EXISTS person_note_tsv_trigger()');
        }

        Schema::dropIfExists('person');
    }
};
