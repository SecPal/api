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
     * Creates immutable audit log for all role assignment actions.
     * This table records history of role assignments, revocations, and expirations
     * for compliance and debugging purposes.
     */
    public function up(): void
    {
        Schema::create('role_assignments_log', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Foreign keys (UUID to match users table)
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');

            // Action tracking
            $table->enum('action', ['assigned', 'revoked', 'expired', 'extended']);

            // Temporal validity (copied from assignment at time of logging)
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            // Audit trail metadata
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('reason')->nullable();

            // Immutable timestamp (only created_at, no updated_at)
            $table->timestamp('created_at')->useCurrent();

            // Indexes for querying
            $table->index(['user_id', 'created_at']);
            $table->index(['role_id', 'created_at']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_assignments_log');
    }
};
