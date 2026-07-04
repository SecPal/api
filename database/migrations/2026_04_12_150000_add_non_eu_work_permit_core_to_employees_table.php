<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\TenantKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WORK_PERMIT_CHECK_CONSTRAINT = 'employees_work_permit_type_check';

    /**
     * @var list<string>
     */
    private const SQLITE_TRANSITIONAL_WORK_PERMIT_TYPES = [
        'none',
        'unlimited',
        'limited',
        'temporary',
        'permanent',
        'blue_card',
        'seasonal',
        'student',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('work_permit_number_enc')->nullable()->after('work_permit_type');
            $table->string('work_permit_copy_path')->nullable()->after('work_permit_expiry');
            $table->string('work_permit_issued_by', 255)->nullable()->after('work_permit_copy_path');
            $table->timestamp('work_permit_copy_deleted_at')->nullable()->after('work_permit_issued_by');
        });

        /** @var Illuminate\Database\Eloquent\Collection<int, TenantKey> $tenantKeys */
        $tenantKeys = TenantKey::all()->keyBy('id');

        DB::table('employees')
            ->select(['id', 'tenant_id', 'work_permit_number'])
            ->whereNotNull('work_permit_number')
            ->orderBy('id')
            ->chunk(100, function ($employees) use ($tenantKeys): void {
                foreach ($employees as $employee) {
                    if (! is_string($employee->work_permit_number) || $employee->work_permit_number === '') {
                        continue;
                    }

                    $tenantId = $employee->tenant_id;
                    if (! is_numeric($tenantId)) {
                        throw new RuntimeException('Unexpected non-numeric tenant_id in employees table.');
                    }

                    $tenantKey = $tenantKeys->get((int) $tenantId);
                    if (! $tenantKey instanceof TenantKey) {
                        throw new RuntimeException("TenantKey not found for tenant {$tenantId}.");
                    }

                    $encryptedPermit = $tenantKey->encrypt($employee->work_permit_number);
                    $encodedPermit = json_encode([
                        'ciphertext' => base64_encode($encryptedPermit['ciphertext']),
                        'nonce' => base64_encode($encryptedPermit['nonce']),
                    ]);

                    if ($encodedPermit === false) {
                        throw new RuntimeException('Failed to encode encrypted work permit number.');
                    }

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'work_permit_number_enc' => $encodedPermit,
                        ]);
                }
            });

        $this->prepareWorkPermitTypeRewrite();

        DB::table('employees')
            ->where('work_permit_type', 'unlimited')
            ->update(['work_permit_type' => 'permanent']);

        DB::table('employees')
            ->where('work_permit_type', 'limited')
            ->update(['work_permit_type' => 'temporary']);

        $this->finishWorkPermitTypeRewrite([
            'none',
            'temporary',
            'permanent',
            'blue_card',
            'seasonal',
            'student',
        ]);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('work_permit_number');
            $table->index('work_permit_expiry', 'idx_employees_work_permit_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_permit_number')->nullable()->after('work_permit_type');
        });

        DB::table('employees')
            ->select(['id', 'tenant_id', 'work_permit_number_enc'])
            ->whereNotNull('work_permit_number_enc')
            ->orderBy('id')
            ->chunk(100, function ($employees): void {
                foreach ($employees as $employee) {
                    if (! is_string($employee->work_permit_number_enc)) {
                        continue;
                    }

                    $decodedPermit = json_decode($employee->work_permit_number_enc, true);
                    if (! is_array($decodedPermit)
                        || ! isset($decodedPermit['ciphertext'], $decodedPermit['nonce'])
                        || ! is_string($decodedPermit['ciphertext'])
                        || ! is_string($decodedPermit['nonce'])) {
                        throw new RuntimeException('Invalid encrypted work permit payload.');
                    }

                    $ciphertext = base64_decode($decodedPermit['ciphertext'], true);
                    $nonce = base64_decode($decodedPermit['nonce'], true);

                    if (! is_string($ciphertext) || ! is_string($nonce)) {
                        throw new RuntimeException('Failed to decode encrypted work permit payload.');
                    }

                    /** @var TenantKey $tenantKey */
                    $tenantKey = TenantKey::findOrFail($employee->tenant_id);
                    $permitNumber = $tenantKey->decrypt($ciphertext, $nonce);

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'work_permit_number' => $permitNumber,
                        ]);
                }
            });

        $this->prepareWorkPermitTypeRewrite();

        DB::table('employees')
            ->where('work_permit_type', 'permanent')
            ->update(['work_permit_type' => 'unlimited']);

        DB::table('employees')
            ->whereIn('work_permit_type', ['temporary', 'blue_card', 'seasonal', 'student'])
            ->update(['work_permit_type' => 'limited']);

        $this->finishWorkPermitTypeRewrite([
            'none',
            'limited',
            'unlimited',
        ]);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('idx_employees_work_permit_expiry');
            $table->dropColumn([
                'work_permit_number_enc',
                'work_permit_copy_path',
                'work_permit_issued_by',
                'work_permit_copy_deleted_at',
            ]);
        });
    }

    /**
     * @param  list<string>  $allowedValues
     */
    private function addWorkPermitTypeConstraint(array $allowedValues): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $quotedValues = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowedValues,
        ));

        DB::statement(sprintf(
            'ALTER TABLE employees ADD CONSTRAINT %s CHECK (work_permit_type IN (%s))',
            self::WORK_PERMIT_CHECK_CONSTRAINT,
            $quotedValues,
        ));
    }

    private function dropWorkPermitTypeConstraint(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE employees DROP CONSTRAINT IF EXISTS %s',
            self::WORK_PERMIT_CHECK_CONSTRAINT,
        ));
    }

    private function prepareWorkPermitTypeRewrite(): void
    {
        $driverName = Schema::getConnection()->getDriverName();

        if ($driverName === 'sqlite') {
            $this->rebuildSqliteWorkPermitTypeConstraint(self::SQLITE_TRANSITIONAL_WORK_PERMIT_TYPES);

            return;
        }

        if ($driverName !== 'pgsql') {
            throw new RuntimeException(sprintf(
                'Unsupported database driver "%s" for work permit type rewrite migration.',
                $driverName,
            ));
        }

        $this->dropWorkPermitTypeConstraint();
    }

    /**
     * @param  list<string>  $allowedValues
     */
    private function finishWorkPermitTypeRewrite(array $allowedValues): void
    {
        $driverName = Schema::getConnection()->getDriverName();

        if ($driverName === 'sqlite') {
            $this->rebuildSqliteWorkPermitTypeConstraint($allowedValues);

            return;
        }

        if ($driverName !== 'pgsql') {
            throw new RuntimeException(sprintf(
                'Unsupported database driver "%s" for work permit type rewrite migration.',
                $driverName,
            ));
        }

        $this->addWorkPermitTypeConstraint($allowedValues);
    }

    /**
     * @param  list<string>  $allowedValues
     */
    private function rebuildSqliteWorkPermitTypeConstraint(array $allowedValues): void
    {
        $temporaryTable = '__employees_work_permit_rewrite';

        /** @var object{sql: string}|null $tableDefinition */
        $tableDefinition = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = 'employees'"
        );

        if (! is_object($tableDefinition) || ! isset($tableDefinition->sql) || ! is_string($tableDefinition->sql)) {
            throw new RuntimeException('Unable to load SQLite employees table definition for work permit rewrite.');
        }

        $rewrittenTableSql = $this->replaceSqliteWorkPermitTypeCheck($tableDefinition->sql, $allowedValues);

        /** @var list<object{sql: string}> $schemaObjects */
        $schemaObjects = DB::select(
            "select sql from sqlite_master where tbl_name = 'employees' and type in ('index', 'trigger') and sql is not null order by type, name"
        );

        /** @var list<object{name: string}> $columns */
        $columns = DB::select('pragma table_info("employees")');
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
            DB::statement(sprintf('alter table "employees" rename to "%s"', $temporaryTable));
            DB::statement($rewrittenTableSql);
            DB::statement(sprintf(
                'insert into "employees" (%s) select %s from "%s"',
                $columnList,
                $columnList,
                $temporaryTable,
            ));
            DB::statement(sprintf('drop table "%s"', $temporaryTable));

            foreach ($schemaObjects as $schemaObject) {
                DB::statement($schemaObject->sql);
            }
        } finally {
            if ($foreignKeysEnabled) {
                DB::statement('pragma foreign_keys = on');
            }
        }
    }

    /**
     * @param  list<string>  $allowedValues
     */
    private function replaceSqliteWorkPermitTypeCheck(string $tableSql, array $allowedValues): string
    {
        $quotedValues = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowedValues,
        ));

        $rewrittenSql = preg_replace(
            '/"work_permit_type" varchar check \("work_permit_type" in \([^)]+\)\)/',
            sprintf(
                '"work_permit_type" varchar check ("work_permit_type" in (%s))',
                $quotedValues,
            ),
            $tableSql,
            1,
            $replacementCount,
        );

        if (! is_string($rewrittenSql) || $replacementCount !== 1) {
            throw new RuntimeException('Unable to rewrite SQLite work permit type check constraint.');
        }

        return $rewrittenSql;
    }
};
