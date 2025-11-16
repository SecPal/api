<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the secret_shares table for managing access control to secrets.
     * A share grants read/write/admin permission to a user or role.
     */
    public function up(): void
    {
        Schema::create('secret_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('secret_id')->constrained('secrets')->onDelete('cascade');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('role_id')->nullable()->constrained('roles')->onDelete('cascade');
            $table->enum('permission', ['read', 'write', 'admin']);
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('secret_id');
            $table->index('user_id');
            $table->index('role_id');
            $table->index('expires_at');

            // Unique constraints: One share per secret+user or secret+role
            $table->unique(['secret_id', 'user_id']);
            $table->unique(['secret_id', 'role_id']);
        });

        // Add CHECK constraint: Either user_id OR role_id must be set, not both
        DB::statement('ALTER TABLE secret_shares ADD CONSTRAINT secret_shares_user_xor_role CHECK ((user_id IS NOT NULL AND role_id IS NULL) OR (user_id IS NULL AND role_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secret_shares');
    }
};
