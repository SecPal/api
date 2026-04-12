<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_work_permit_type_check');

        DB::table('employees')
            ->where('work_permit_type', 'unlimited')
            ->update(['work_permit_type' => 'permanent']);

        DB::table('employees')
            ->where('work_permit_type', 'limited')
            ->update(['work_permit_type' => 'temporary']);

        DB::statement(<<<'SQL'
            ALTER TABLE employees
            ADD CONSTRAINT employees_work_permit_type_check
            CHECK (work_permit_type IN ('none', 'temporary', 'permanent', 'blue_card', 'seasonal', 'student'))
        SQL);

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

        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_work_permit_type_check');

        DB::table('employees')
            ->where('work_permit_type', 'permanent')
            ->update(['work_permit_type' => 'unlimited']);

        DB::table('employees')
            ->whereIn('work_permit_type', ['temporary', 'blue_card', 'seasonal', 'student'])
            ->update(['work_permit_type' => 'limited']);

        DB::statement(<<<'SQL'
            ALTER TABLE employees
            ADD CONSTRAINT employees_work_permit_type_check
            CHECK (work_permit_type IN ('none', 'limited', 'unlimited'))
        SQL);

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
};
