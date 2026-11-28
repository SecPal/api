<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
        Schema::create('tenant_kek_generations', function (Blueprint $table) {
            $table->bigInteger('generation')->primary();
            $table->char('fingerprint', 64)->unique();
            $table->uuid('rotation_id')->nullable()->unique();
        });

        DB::statement('ALTER TABLE tenant_kek_generations ADD CHECK (generation > 0)');

        Schema::create('tenant_kek_state', function (Blueprint $table) {
            $table->integer('singleton')->primary();
            $table->uuid('kek_id');
            $table->bigInteger('generation');
            $table->foreign('generation')->references('generation')->on('tenant_kek_generations');
            $table->unique(['kek_id', 'generation']);
        });

        DB::statement('ALTER TABLE tenant_kek_state ADD CHECK (singleton = 1)');

        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('kek_id');
            $table->bigInteger('kek_generation');
            $table->foreign(['kek_id', 'kek_generation'], 'tenant_keys_kek_reference')
                ->references(['kek_id', 'generation'])->on('tenant_kek_state')->deferrable();
            $table->binary('dek_wrapped');
            $table->binary('dek_nonce');
            $table->binary('idx_wrapped');
            $table->binary('idx_nonce');
            $table->integer('key_version')->default(1);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_keys');
        Schema::dropIfExists('tenant_kek_state');
        Schema::dropIfExists('tenant_kek_generations');
    }
};
