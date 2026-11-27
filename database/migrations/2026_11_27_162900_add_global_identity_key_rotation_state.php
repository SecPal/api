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
        Schema::table('global_identity_working_keys', function (Blueprint $table): void {
            $table->bigInteger('previous_lookup_version')->nullable();
            $table->boolean('lookup_cleanup')->default(false);
            $table->string('delivery_block_reason', 32)->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE global_identity_working_keys ADD CONSTRAINT global_identity_lookup_rotation
                CHECK (
                    (previous_lookup_version IS NULL AND NOT lookup_cleanup)
                    OR (previous_lookup_version IS NOT NULL
                        AND purpose = 'secpal/global-identity/user-email-lookup/v1'
                        AND state = 'write-active' AND previous_lookup_version = key_version - 1
                        AND previous_lookup_version > 0)
                ), ADD CONSTRAINT global_identity_delivery_key_block
                CHECK (
                    delivery_block_reason IS NULL
                    OR (purpose = 'secpal/global-identity/operation-delivery-secret/v1'
                        AND state <> 'candidate'
                        AND delivery_block_reason IN ('security_revoked', 'key_unavailable'))
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE global_identity_working_keys DROP CONSTRAINT global_identity_lookup_rotation, DROP CONSTRAINT global_identity_delivery_key_block');
        Schema::table('global_identity_working_keys', function (Blueprint $table): void {
            $table->dropColumn(['previous_lookup_version', 'lookup_cleanup', 'delivery_block_reason']);
        });
    }
};
