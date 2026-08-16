<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Customer duplicate guards require PostgreSQL generated columns.');
        }

        if (DB::table('customer_establishments')
            ->where(function (Builder $query): void {
                $query->whereNotNull('contact_name')
                    ->orWhereNotNull('phone')
                    ->orWhereNotNull('email')
                    ->orWhereNotNull('comments');
            })->exists()) {
            throw new RuntimeException(
                'Customer establishment contact data must be explicitly encrypted before this migration.'
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE customers
            ADD COLUMN vat_id_normalized text GENERATED ALWAYS AS (
                NULLIF(regexp_replace(lower(btrim(COALESCE(vat_id, ''))), '[^[:alnum:]]+', '', 'g'), '')
            ) STORED,
            ADD COLUMN name_billing_address_normalized text GENERATED ALWAYS AS (
                regexp_replace(lower(btrim(name)), '[[:space:]]+', ' ', 'g') || '|' ||
                regexp_replace(lower(btrim(billing_address->>'street')), '[[:space:]]+', ' ', 'g') || '|' ||
                regexp_replace(lower(btrim(billing_address->>'city')), '[[:space:]]+', ' ', 'g') || '|' ||
                regexp_replace(lower(btrim(billing_address->>'postal_code')), '[[:space:]]+', ' ', 'g') || '|' ||
                regexp_replace(lower(btrim(billing_address->>'country')), '[[:space:]]+', ' ', 'g')
            ) STORED
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE customer_establishments
            RENAME COLUMN contact_name TO contact_name_enc
            SQL);
        DB::statement('ALTER TABLE customer_establishments RENAME COLUMN phone TO phone_enc');
        DB::statement('ALTER TABLE customer_establishments RENAME COLUMN email TO email_enc');
        DB::statement('ALTER TABLE customer_establishments RENAME COLUMN comments TO comments_enc');
        DB::statement(<<<'SQL'
            ALTER TABLE customer_establishments
            ALTER COLUMN contact_name_enc TYPE text,
            ALTER COLUMN phone_enc TYPE text,
            ALTER COLUMN email_enc TYPE text
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_tenant_legal_entity_vat_normalized_unique
            ON customers (tenant_id, legal_entity_id, vat_id_normalized)
            WHERE vat_id_normalized IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_tenant_legal_entity_name_address_normalized_unique
            ON customers (tenant_id, legal_entity_id, name_billing_address_normalized)
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Customer duplicate guards are intentionally irreversible.');
    }
};
