<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_identity_working_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key_id');
            $table->bigInteger('key_version');
            $table->string('purpose', 96);
            $table->string('algorithm', 32);
            $table->string('state', 16);
            $table->string('root_id', 64);
            $table->bigInteger('root_version');
            $table->integer('wrapping_format_version');
            $table->string('wrapping_algorithm', 32);
            $table->binary('wrapping_nonce');
            $table->binary('wrapped_key');
            $table->timestampTz('created_at');
            $table->timestampTz('activated_at')->nullable();

            $table->unique(['key_id', 'key_version'], 'global_identity_key_versions');
            $table->unique(['purpose', 'key_version'], 'global_identity_purpose_versions');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE global_identity_working_keys
                ADD CONSTRAINT global_identity_positive_versions
                    CHECK (key_version > 0 AND root_version > 0 AND wrapping_format_version > 0),
                ADD CONSTRAINT global_identity_key_lifecycle CHECK (
                    (state = 'candidate' AND activated_at IS NULL)
                    OR (state IN ('write-active', 'decrypt-only', 'retired') AND activated_at IS NOT NULL)
                )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX global_identity_key_candidate_or_active
                ON global_identity_working_keys (purpose, state)
                WHERE state IN ('candidate', 'write-active')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('global_identity_working_keys');
    }
};
