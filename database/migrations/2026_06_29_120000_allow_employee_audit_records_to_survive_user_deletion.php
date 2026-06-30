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

        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_user_id_foreign');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN user_id DROP NOT NULL');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::table('role_assignments_log')
            ->whereNull('user_id')
            ->delete();

        DB::statement('ALTER TABLE onboarding_form_submissions DROP CONSTRAINT IF EXISTS onboarding_form_submissions_reviewed_by_foreign');
        DB::statement('ALTER TABLE onboarding_form_submissions ADD CONSTRAINT onboarding_form_submissions_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id)');

        DB::table('onboarding_submission_files')
            ->whereNull('uploaded_by')
            ->delete();

        DB::statement('ALTER TABLE onboarding_submission_files DROP CONSTRAINT IF EXISTS onboarding_submission_files_uploaded_by_foreign');
        DB::statement('ALTER TABLE onboarding_submission_files ALTER COLUMN uploaded_by SET NOT NULL');
        DB::statement('ALTER TABLE onboarding_submission_files ADD CONSTRAINT onboarding_submission_files_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id)');

        DB::table('employee_documents')
            ->whereNull('uploaded_by')
            ->delete();

        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_uploaded_by_foreign');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN uploaded_by SET NOT NULL');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_user_id_foreign');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN user_id SET NOT NULL');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }
};
