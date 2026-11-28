<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cutover migration installs this schema after retiring legacy references.
 * Before activation, consumers can install it in an isolated PostgreSQL schema
 * with the functional tenants table.
 */
final class EstablishmentSchema
{
    public function apply(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \LogicException('Establishment schema requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            Schema::create('establishments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->unique(['tenant_id', 'id']);
            });

            Schema::create('establishment_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->uuid('establishment_id');
                $table->timestampTz('effective_at', 6)->default(DB::raw('clock_timestamp()'));
                $table->string('name');
                $table->string('code')->nullable();
                $table->jsonb('address');
                $table->boolean('is_closed');

                $table->foreign(['tenant_id', 'establishment_id'], 'establishment_versions_owner_foreign')
                    ->references(['tenant_id', 'id'])->on('establishments')->restrictOnDelete();
                $table->unique(['tenant_id', 'establishment_id', 'effective_at'], 'establishment_versions_effective_unique');
            });

            DB::statement(<<<'SQL'
                ALTER TABLE establishment_versions ADD CONSTRAINT establishment_versions_address_object
                    CHECK (jsonb_typeof(address) = 'object')
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE FUNCTION reject_establishment_version_mutation() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'Establishment history is immutable' USING ERRCODE = '23514';
                END;
                $$;

                CREATE TRIGGER establishment_versions_immutable
                    BEFORE UPDATE OR DELETE ON establishment_versions
                    FOR EACH ROW EXECUTE FUNCTION reject_establishment_version_mutation();
                SQL);
        });
    }
}
