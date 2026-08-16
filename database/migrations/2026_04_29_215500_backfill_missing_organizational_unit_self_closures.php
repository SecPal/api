<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Restores missing depth-0 self-reference rows so hierarchy writes and
     * parent lookups remain consistent for existing organizational units.
     */
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            INSERT INTO organizational_unit_closures (ancestor_id, descendant_id, depth)
            SELECT ou.id, ou.id, 0
            FROM organizational_units ou
            WHERE ou.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM organizational_unit_closures closure
                  WHERE closure.ancestor_id = ou.id
                    AND closure.descendant_id = ou.id
              )
            SQL
        );
    }

    /**
     * Reverse the migrations.
     *
     * This data-repair migration is intentionally irreversible.
     */
    public function down(): void
    {
        // No-op: deleting repaired self-closure rows would risk corrupting hierarchy data.
    }
};
