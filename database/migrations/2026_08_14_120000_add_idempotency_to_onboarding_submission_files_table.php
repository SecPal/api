<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_submission_files', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('onboarding_form_submission_id');
            $table->string('idempotency_key', 64)->nullable()->after('document_subtype');
            $table->char('content_fingerprint', 64)->nullable()->after('idempotency_key');
        });

        DB::statement(<<<'SQL'
            UPDATE onboarding_submission_files AS files
            SET tenant_id = employees.tenant_id
            FROM onboarding_form_submissions AS submissions
            INNER JOIN employees ON employees.id = submissions.employee_id
            WHERE submissions.id = files.onboarding_form_submission_id
            SQL);
        DB::statement('ALTER TABLE onboarding_submission_files ALTER COLUMN tenant_id SET NOT NULL');

        Schema::table('onboarding_submission_files', function (Blueprint $table): void {
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenant_keys')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX onboarding_submission_files_idempotency_unique
            ON onboarding_submission_files (tenant_id, idempotency_key)
            WHERE idempotency_key IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS onboarding_submission_files_idempotency_unique');

        Schema::table('onboarding_submission_files', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'idempotency_key', 'content_fingerprint']);
        });
    }
};
