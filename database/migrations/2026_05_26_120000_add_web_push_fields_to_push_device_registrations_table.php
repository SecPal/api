<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_device_registrations', function (Blueprint $table) {
            $table->string('browser_name', 80)->nullable()->after('sdk_int');
            $table->string('browser_version', 50)->nullable()->after('browser_name');
            $table->string('service_worker_scope', 255)->nullable()->after('browser_version');
            $table->string('subscription_endpoint_origin', 255)->nullable()->after('service_worker_scope');
            $table->text('subscription_p256dh_enc')->nullable()->after('subscription_endpoint_origin');
            $table->text('subscription_auth_enc')->nullable()->after('subscription_p256dh_enc');
            $table->timestampTz('subscription_expires_at')->nullable()->after('subscription_auth_enc');
        });

        Schema::table('push_device_registrations', function (Blueprint $table) {
            $table->string('package_name', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('push_device_registrations')
            ->whereNull('package_name')
            ->update(['package_name' => 'app.secpal']);

        Schema::table('push_device_registrations', function (Blueprint $table) {
            $table->string('package_name', 120)->nullable(false)->change();
        });

        Schema::table('push_device_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'browser_name',
                'browser_version',
                'service_worker_scope',
                'subscription_endpoint_origin',
                'subscription_p256dh_enc',
                'subscription_auth_enc',
                'subscription_expires_at',
            ]);
        });
    }
};
