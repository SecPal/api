<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_data_imports', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('source_name');
            $table->text('source_url');
            $table->string('source_etag')->nullable();
            $table->string('source_last_modified')->nullable();
            $table->string('source_sha256', 64)->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('row_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('license')->nullable();
            $table->text('attribution')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'status']);
            $table->index('activated_at');
        });

        Schema::create('address_streets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('address_data_imports')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('name');
            $table->string('postal_code', 10);
            $table->string('locality');
            $table->string('regional_key')->nullable();
            $table->string('borough')->nullable();
            $table->string('suburb')->nullable();
            $table->string('name_search');
            $table->string('name_search_ascii');
            $table->string('locality_search');
            $table->string('locality_search_ascii');
            $table->timestamps();

            $table->index(['import_id', 'postal_code']);
            $table->index(['import_id', 'locality_search']);
            $table->index(['import_id', 'locality_search_ascii']);
            $table->index(['import_id', 'name_search']);
            $table->index(['import_id', 'name_search_ascii']);
            $table->index(['import_id', 'postal_code', 'locality_search', 'name_search'], 'address_streets_import_plz_loc_name_idx');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX address_data_imports_one_active_per_country ON address_data_imports (country_code) WHERE activated_at IS NOT NULL'
            );
            $this->tryCreatePgTrgmIndexes();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS address_data_imports_one_active_per_country');
            DB::statement('DROP INDEX IF EXISTS address_streets_name_search_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS address_streets_locality_search_trgm_idx');
        }

        Schema::dropIfExists('address_streets');
        Schema::dropIfExists('address_data_imports');
    }

    private function tryCreatePgTrgmIndexes(): void
    {
        // Each optional step runs inside its own nested transaction so a PostgreSQL
        // statement error (e.g. pg_trgm installed in a schema outside search_path)
        // only rolls back its own SAVEPOINT instead of aborting the entire migration
        // transaction and silently dropping the just-created address tables.
        try {
            DB::transaction(static function (): void {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            });
        } catch (Throwable) {
            return;
        }

        try {
            DB::transaction(static function (): void {
                DB::statement(
                    'CREATE INDEX address_streets_name_search_trgm_idx ON address_streets USING gin (name_search gin_trgm_ops)',
                );
                DB::statement(
                    'CREATE INDEX address_streets_locality_search_trgm_idx ON address_streets USING gin (locality_search gin_trgm_ops)',
                );
            });
        } catch (Throwable) {
            // Prefix/B-tree indexes remain for lookup when extension or CREATE INDEX fails.
        }
    }
};
