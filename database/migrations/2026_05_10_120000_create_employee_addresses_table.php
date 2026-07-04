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
        Schema::create('employee_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            $table->text('street_enc')->nullable();
            $table->text('house_number_enc')->nullable();
            $table->text('postal_code_enc')->nullable();
            $table->text('city_enc')->nullable();
            $table->text('supplement_enc')->nullable();
            $table->string('country', 2)->nullable();

            $table->date('resided_from')->nullable();
            $table->date('resided_until')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'resided_until']);
            $table->index('tenant_id');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->addSqliteResidedDatesConstraint();
        } else {
            DB::statement('ALTER TABLE employee_addresses ADD CONSTRAINT employee_addresses_resided_dates_chk CHECK (
                resided_from IS NULL OR resided_until IS NULL OR resided_until >= resided_from
            )');
        }

        DB::statement(
            'CREATE UNIQUE INDEX employee_addresses_one_current_per_employee ON employee_addresses (employee_id) WHERE (resided_until IS NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employee_addresses_one_current_per_employee');
        Schema::dropIfExists('employee_addresses');
    }

    private function addSqliteResidedDatesConstraint(): void
    {
        $temporaryTable = '__employee_addresses_resided_dates_rewrite';

        /** @var object{sql: string}|null $tableDefinition */
        $tableDefinition = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = 'employee_addresses'"
        );

        if (! is_object($tableDefinition) || ! isset($tableDefinition->sql) || ! is_string($tableDefinition->sql)) {
            throw new RuntimeException('Unable to load SQLite employee_addresses table definition.');
        }

        $rewrittenTableSql = preg_replace(
            '/\)\s*$/',
            ', check (resided_from IS NULL OR resided_until IS NULL OR resided_until >= resided_from))',
            $tableDefinition->sql,
            1,
            $constraintCount,
        );

        if (! is_string($rewrittenTableSql) || $constraintCount !== 1) {
            throw new RuntimeException('Unable to add SQLite employee_addresses resided date constraint.');
        }

        $temporaryTableSql = preg_replace(
            '/^CREATE TABLE "employee_addresses"/',
            sprintf('CREATE TABLE "%s"', $temporaryTable),
            $rewrittenTableSql,
            1,
            $renamedTableCount,
        );

        if (! is_string($temporaryTableSql) || $renamedTableCount !== 1) {
            throw new RuntimeException('Unable to build temporary SQLite employee_addresses table definition.');
        }

        /** @var list<object{sql: string}> $schemaObjects */
        $schemaObjects = DB::select(
            "select sql from sqlite_master where tbl_name = 'employee_addresses' and type in ('index', 'trigger') and sql is not null order by type, name"
        );

        /** @var list<object{name: string}> $columns */
        $columns = DB::select('pragma table_info("employee_addresses")');
        $columnList = implode(', ', array_map(
            static fn (object $column): string => '"'.$column->name.'"',
            $columns,
        ));

        $foreignKeysEnabledValue = DB::scalar('pragma foreign_keys');
        $foreignKeysEnabled = in_array($foreignKeysEnabledValue, [1, '1'], true);

        if ($foreignKeysEnabled) {
            DB::statement('pragma foreign_keys = off');
        }

        try {
            DB::statement($temporaryTableSql);
            DB::statement(sprintf(
                'insert into "%s" (%s) select %s from "employee_addresses"',
                $temporaryTable,
                $columnList,
                $columnList,
            ));
            DB::statement('drop table "employee_addresses"');
            DB::statement(sprintf('alter table "%s" rename to "employee_addresses"', $temporaryTable));

            foreach ($schemaObjects as $schemaObject) {
                DB::statement($schemaObject->sql);
            }
        } finally {
            if ($foreignKeysEnabled) {
                DB::statement('pragma foreign_keys = on');
            }
        }
    }
};
