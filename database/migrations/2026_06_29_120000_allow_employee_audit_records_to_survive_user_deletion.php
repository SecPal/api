<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_uploaded_by_foreign');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN uploaded_by DROP NOT NULL');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE onboarding_submission_files DROP CONSTRAINT IF EXISTS onboarding_submission_files_uploaded_by_foreign');
        DB::statement('ALTER TABLE onboarding_submission_files ALTER COLUMN uploaded_by DROP NOT NULL');
        DB::statement('ALTER TABLE onboarding_submission_files ADD CONSTRAINT onboarding_submission_files_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE onboarding_form_submissions DROP CONSTRAINT IF EXISTS onboarding_form_submissions_reviewed_by_foreign');
        DB::statement('ALTER TABLE onboarding_form_submissions ADD CONSTRAINT onboarding_form_submissions_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE onboarding_form_submissions DROP CONSTRAINT IF EXISTS onboarding_form_submissions_reviewed_by_foreign');
        DB::statement('ALTER TABLE onboarding_form_submissions ADD CONSTRAINT onboarding_form_submissions_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE onboarding_submission_files DROP CONSTRAINT IF EXISTS onboarding_submission_files_uploaded_by_foreign');
        DB::statement('ALTER TABLE onboarding_submission_files ADD CONSTRAINT onboarding_submission_files_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_uploaded_by_foreign');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id)');
    }
};
