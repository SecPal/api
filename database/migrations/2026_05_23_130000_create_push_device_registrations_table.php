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
        Schema::create('push_device_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('installation_id');
            $table->string('platform', 32);
            $table->string('provider', 32);
            $table->string('device_name', 120);
            $table->text('push_token_enc');
            $table->char('token_last_eight', 8);
            $table->string('last_lifecycle_event', 32);
            $table->string('package_name', 120);
            $table->string('package_version_name', 50)->nullable();
            $table->unsignedInteger('package_version_code')->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('android_version', 30)->nullable();
            $table->unsignedInteger('sdk_int')->nullable();
            $table->string('bootstrap_version', 16);
            $table->unsignedInteger('schema_version');
            $table->unsignedInteger('push_metadata_revision');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'installation_id'], 'push_device_registrations_tenant_user_installation_unique');
            $table->index(['tenant_id', 'provider'], 'push_device_registrations_tenant_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_device_registrations');
    }
};
