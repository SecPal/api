<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE internal_cost_centers
            DROP CONSTRAINT internal_cost_centers_code_check,
            ADD CONSTRAINT internal_cost_centers_code_check
            CHECK (
                char_length(code) BETWEEN 1 AND 64
                AND code !~ '^[[:space:]]*$'
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE internal_cost_centers
            DROP CONSTRAINT internal_cost_centers_code_check,
            ADD CONSTRAINT internal_cost_centers_code_check
            CHECK (
                char_length(code) BETWEEN 1 AND 64
                AND code !~ '^[[:space:]]|[[:space:]]$'
            )
            SQL);
    }
};
