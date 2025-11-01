<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
        Schema::table('tenant_keys', function (Blueprint $table) {
            // Convert binary columns to base64-encoded text for reliable cross-DB support
            // Base64 of 32-byte key = 44 chars, 24-byte nonce = 32 chars
            $table->string('dek_wrapped', 64)->change();
            $table->string('dek_nonce', 64)->change();
            $table->string('idx_wrapped', 64)->change();
            $table->string('idx_nonce', 64)->change();
        });

        Schema::table('person', function (Blueprint $table) {
            // Base64 of 32-byte HMAC-SHA256 = 44 chars
            $table->string('email_idx', 64)->change();
            $table->string('phone_idx', 64)->change();
            // Encrypted fields also need TEXT (Laravel encrypted cast generates variable-length base64)
            $table->text('email_enc')->change();
            $table->text('phone_enc')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_keys', function (Blueprint $table) {
            $table->binary('dek_wrapped')->change();
            $table->binary('dek_nonce')->change();
            $table->binary('idx_wrapped')->change();
            $table->binary('idx_nonce')->change();
        });

        Schema::table('person', function (Blueprint $table) {
            $table->binary('email_idx')->change();
            $table->binary('phone_idx')->change();
            $table->binary('email_enc')->change();
            $table->binary('phone_enc')->change();
        });
    }
};
