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
     * Tenant-specific encryption keys (envelope encryption).
     * - dek_wrapped: Encrypted Data Encryption Key (DEK) for field encryption
     * - idx_wrapped: Encrypted index key for blind indexes (HMAC)
     * - Both wrapped with KEK (master key from file)
     */
    public function up(): void
    {
        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();

            // Wrapped keys (encrypted with KEK) - stored as BYTEA in PostgreSQL
            $table->binary('dek_wrapped');
            $table->binary('dek_nonce');
            $table->binary('idx_wrapped');
            $table->binary('idx_nonce');

            // Key version for rotation tracking
            $table->unsignedInteger('key_version')->default(1);

            $table->timestampTz('created_at')->useCurrent();

            // Index for key rotation queries
            $table->index('key_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_keys');
    }
};
