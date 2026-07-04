<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('onboarding_submission_files', function (Blueprint $table): void {
            $table->string('document_subtype', 64)
                ->nullable()
                ->after('document_type');

            $table->index(
                ['onboarding_form_submission_id', 'document_type', 'document_subtype'],
                'onboarding_sub_files_submission_type_subtype_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onboarding_submission_files', function (Blueprint $table): void {
            $table->dropIndex('onboarding_sub_files_submission_type_subtype_idx');
            $table->dropColumn('document_subtype');
        });
    }
};
