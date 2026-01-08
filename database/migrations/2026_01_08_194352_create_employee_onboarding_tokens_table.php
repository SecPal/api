<?php

// SPDX-FileCopyrightText: 2025 SecPal <info@secpal.de>
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Create employee_onboarding_tokens table for secure magic link authentication.
     *
     * Security features:
     * - Tokens are hashed with bcrypt (never stored as plain text)
     * - Single-use enforcement via completed_at timestamp
     * - 7-day expiry by default
     * - Audit trail (IP address, user agent)
     * - Cascading delete when employee is deleted
     */
    public function up(): void
    {
        Schema::create('employee_onboarding_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255); // Hashed with bcrypt
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->ipAddress('completed_from_ip')->nullable();
            $table->string('completed_user_agent', 500)->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('token');
            $table->index('employee_id');
            $table->index('expires_at');
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_onboarding_tokens');
    }
};
