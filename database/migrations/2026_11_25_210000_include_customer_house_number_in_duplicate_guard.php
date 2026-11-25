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
        DB::statement('DROP INDEX customers_tenant_legal_entity_name_address_normalized_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_tenant_legal_entity_name_address_normalized_unique
            ON customers (
                tenant_id,
                legal_entity_id,
                name_billing_address_normalized,
                (regexp_replace(lower(COALESCE(billing_address->>'house_number', '')), '[[:space:]]+', '', 'g'))
            )
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The previous customer uniqueness rule can reject customers with distinct house numbers.');
    }
};
