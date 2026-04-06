<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('android_enrollment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('device_label', 120)->nullable();
            $table->string('enrollment_mode', 50)->default('device_owner');
            $table->string('update_channel', 50);
            $table->string('release_metadata_url', 2048);
            $table->json('provisioning_profile');
            $table->string('bootstrap_token', 255);
            $table->char('bootstrap_token_lookup_hash', 64)->unique();
            $table->timestamp('bootstrap_token_expires_at');
            $table->timestamp('exchanged_at')->nullable();
            $table->ipAddress('exchanged_from_ip')->nullable();
            $table->string('exchanged_user_agent', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('bootstrap_token_expires_at');
            $table->index('exchanged_at');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('android_enrollment_sessions');
    }
};
