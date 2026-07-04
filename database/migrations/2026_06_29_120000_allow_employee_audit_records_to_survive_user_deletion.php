<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteUserReference(
                'employee_documents',
                'uploaded_by',
                nullable: true,
                onDeleteAction: 'set null',
            );
            $this->rebuildSqliteUserReference(
                'onboarding_submission_files',
                'uploaded_by',
                nullable: true,
                onDeleteAction: 'set null',
            );
            $this->rebuildSqliteUserReference(
                'onboarding_form_submissions',
                'reviewed_by',
                nullable: true,
                onDeleteAction: 'set null',
            );
            $this->rebuildSqliteUserReference(
                'role_assignments_log',
                'user_id',
                nullable: true,
                onDeleteAction: 'set null',
            );

            return;
        }

        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_uploaded_by_foreign');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN uploaded_by DROP NOT NULL');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE onboarding_submission_files DROP CONSTRAINT IF EXISTS onboarding_submission_files_uploaded_by_foreign');
        DB::statement('ALTER TABLE onboarding_submission_files ALTER COLUMN uploaded_by DROP NOT NULL');
        DB::statement('ALTER TABLE onboarding_submission_files ADD CONSTRAINT onboarding_submission_files_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE onboarding_form_submissions DROP CONSTRAINT IF EXISTS onboarding_form_submissions_reviewed_by_foreign');
        DB::statement('ALTER TABLE onboarding_form_submissions ADD CONSTRAINT onboarding_form_submissions_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_user_id_foreign');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN user_id DROP NOT NULL');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::table('role_assignments_log')
            ->whereNull('user_id')
            ->delete();

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteUserReference(
                'onboarding_form_submissions',
                'reviewed_by',
                nullable: true,
                onDeleteAction: null,
            );
            $this->rebuildSqliteUserReference(
                'onboarding_submission_files',
                'uploaded_by',
                nullable: false,
                onDeleteAction: null,
            );
            $this->rebuildSqliteUserReference(
                'employee_documents',
                'uploaded_by',
                nullable: false,
                onDeleteAction: null,
            );
            $this->rebuildSqliteUserReference(
                'role_assignments_log',
                'user_id',
                nullable: false,
                onDeleteAction: 'cascade',
            );

            return;
        }

        DB::statement('ALTER TABLE onboarding_form_submissions DROP CONSTRAINT IF EXISTS onboarding_form_submissions_reviewed_by_foreign');
        DB::statement('ALTER TABLE onboarding_form_submissions ADD CONSTRAINT onboarding_form_submissions_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id)');

        DB::table('onboarding_submission_files')
            ->whereNull('uploaded_by')
            ->delete();

        DB::statement('ALTER TABLE onboarding_submission_files DROP CONSTRAINT IF EXISTS onboarding_submission_files_uploaded_by_foreign');
        DB::statement('ALTER TABLE onboarding_submission_files ALTER COLUMN uploaded_by SET NOT NULL');
        DB::statement('ALTER TABLE onboarding_submission_files ADD CONSTRAINT onboarding_submission_files_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id)');

        DB::table('employee_documents')
            ->whereNull('uploaded_by')
            ->delete();

        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_uploaded_by_foreign');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN uploaded_by SET NOT NULL');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users(id)');

        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_user_id_foreign');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN user_id SET NOT NULL');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    private function rebuildSqliteUserReference(
        string $table,
        string $column,
        bool $nullable,
        ?string $onDeleteAction,
    ): void {
        $this->rebuildSqliteTable($table, function (string $tableSql) use ($column, $nullable, $onDeleteAction): string {
            $rewrittenSql = preg_replace(
                sprintf('/"%s" varchar(?: not null)?/', preg_quote($column, '/')),
                $nullable ? sprintf('"%s" varchar', $column) : sprintf('"%s" varchar not null', $column),
                $tableSql,
                1,
                $columnReplacementCount,
            );

            if (! is_string($rewrittenSql) || $columnReplacementCount !== 1) {
                throw new RuntimeException(sprintf(
                    'Unable to rewrite SQLite %s.%s nullability.',
                    $table,
                    $column,
                ));
            }

            $rewrittenSql = preg_replace(
                sprintf(
                    '/foreign key\("%s"\) references "users"\("id"\)(?: on delete (?:cascade|set null))?/',
                    preg_quote($column, '/'),
                ),
                sprintf(
                    'foreign key("%s") references "users"("id")%s',
                    $column,
                    $onDeleteAction === null ? '' : ' on delete '.$onDeleteAction,
                ),
                $rewrittenSql,
                1,
                $foreignKeyReplacementCount,
            );

            if (! is_string($rewrittenSql) || $foreignKeyReplacementCount !== 1) {
                throw new RuntimeException(sprintf(
                    'Unable to rewrite SQLite %s.%s foreign key.',
                    $table,
                    $column,
                ));
            }

            return $rewrittenSql;
        });
    }

    /**
     * @param  callable(string): string  $rewriteTableSql
     */
    private function rebuildSqliteTable(string $table, callable $rewriteTableSql): void
    {
        $temporaryTable = '__'.$table.'_rewrite';

        /** @var object{sql: string}|null $tableDefinition */
        $tableDefinition = DB::selectOne(
            sprintf(
                "select sql from sqlite_master where type = 'table' and name = '%s'",
                $table,
            )
        );

        if (! is_object($tableDefinition) || ! isset($tableDefinition->sql) || ! is_string($tableDefinition->sql)) {
            throw new RuntimeException(sprintf(
                'Unable to load SQLite %s table definition.',
                $table,
            ));
        }

        $rewrittenTableSql = $rewriteTableSql($tableDefinition->sql);
        $temporaryTableSql = preg_replace(
            sprintf('/^CREATE TABLE "%s"/', preg_quote($table, '/')),
            sprintf('CREATE TABLE "%s"', $temporaryTable),
            $rewrittenTableSql,
            1,
            $renamedTableCount,
        );

        if (! is_string($temporaryTableSql) || $renamedTableCount !== 1) {
            throw new RuntimeException(sprintf(
                'Unable to build temporary SQLite %s table definition.',
                $table,
            ));
        }

        /** @var list<object{sql: string}> $schemaObjects */
        $schemaObjects = DB::select(
            sprintf(
                "select sql from sqlite_master where tbl_name = '%s' and type in ('index', 'trigger') and sql is not null order by type, name",
                $table,
            )
        );

        /** @var list<object{name: string}> $columns */
        $columns = DB::select(sprintf('pragma table_info("%s")', $table));
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
                'insert into "%s" (%s) select %s from "%s"',
                $temporaryTable,
                $columnList,
                $columnList,
                $table,
            ));
            DB::statement(sprintf('drop table "%s"', $table));
            DB::statement(sprintf('alter table "%s" rename to "%s"', $temporaryTable, $table));

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
