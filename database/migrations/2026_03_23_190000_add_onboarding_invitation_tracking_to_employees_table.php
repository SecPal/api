<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('onboarding_invitation_status', 32)
                ->default('not_requested')
                ->after('onboarding_completed_at');
            $table->timestamp('onboarding_invitation_requested_at')
                ->nullable()
                ->after('onboarding_invitation_status');
            $table->timestamp('onboarding_invitation_token_created_at')
                ->nullable()
                ->after('onboarding_invitation_requested_at');
            $table->timestamp('onboarding_invitation_mail_sent_at')
                ->nullable()
                ->after('onboarding_invitation_token_created_at');
            $table->timestamp('onboarding_invitation_mail_failed_at')
                ->nullable()
                ->after('onboarding_invitation_mail_sent_at');
            $table->text('onboarding_invitation_failure_reason')
                ->nullable()
                ->after('onboarding_invitation_mail_failed_at');

            $table->index('onboarding_invitation_status');
        });

        DB::table('employees')
            ->whereNull('onboarding_invitation_status')
            ->update(['onboarding_invitation_status' => 'not_requested']);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['onboarding_invitation_status']);
            $table->dropColumn([
                'onboarding_invitation_status',
                'onboarding_invitation_requested_at',
                'onboarding_invitation_token_created_at',
                'onboarding_invitation_mail_sent_at',
                'onboarding_invitation_mail_failed_at',
                'onboarding_invitation_failure_reason',
            ]);
        });
    }
};
