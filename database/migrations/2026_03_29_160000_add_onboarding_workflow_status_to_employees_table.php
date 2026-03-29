<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('onboarding_workflow_status', 64)
                ->nullable()
                ->after('onboarding_completed_at');

            $table->index('onboarding_workflow_status');
        });

        DB::table('employees')
            ->where('status', Employee::STATUS_PRE_CONTRACT)
            ->update(['onboarding_workflow_status' => Employee::WORKFLOW_STATUS_INVITED]);

        DB::table('employees')
            ->whereIn('status', [
                Employee::STATUS_ACTIVE,
                Employee::STATUS_ON_LEAVE,
                Employee::STATUS_TERMINATED,
            ])
            ->update(['onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACTIVE]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['onboarding_workflow_status']);
            $table->dropColumn('onboarding_workflow_status');
        });
    }
};
