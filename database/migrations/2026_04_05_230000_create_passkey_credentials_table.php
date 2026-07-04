<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkey_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('credential_id')->unique();
            $table->string('label', 100);
            $table->json('transports')->nullable();
            $table->string('authenticator_attachment')->nullable();
            $table->uuid('aaguid')->nullable();
            $table->string('attestation_type');
            $table->text('credential_public_key');
            $table->text('user_handle');
            $table->unsignedBigInteger('counter')->default(0);
            $table->boolean('user_verified')->default(false);
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkey_credentials');
    }
};
