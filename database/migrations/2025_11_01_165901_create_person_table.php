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
        Schema::create('person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->onDelete('cascade');
            $table->binary('email_enc');
            $table->binary('email_idx');
            $table->binary('phone_enc');
            $table->binary('phone_idx');
            $table->text('note_enc')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email_idx']);
            $table->index(['tenant_id', 'phone_idx']);
        });

        // PostgreSQL-specific full-text search support
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE person ADD COLUMN note_tsv tsvector');
            DB::statement('CREATE INDEX person_note_tsv_idx ON person USING GIN (note_tsv)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person');
    }
};
