<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\TenantKey;
use App\Traits\NormalizesPersonFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use NormalizesPersonFields;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('phone_enc')->nullable()->after('email');
            $table->string('phone_idx', 64)->nullable()->after('phone_enc');
        });

        /** @var Illuminate\Database\Eloquent\Collection<int, TenantKey> $tenantKeys */
        $tenantKeys = TenantKey::all()->keyBy('id');

        DB::table('employees')
            ->select(['id', 'tenant_id', 'phone'])
            ->whereNotNull('phone')
            ->orderBy('id')
            ->chunk(100, function ($employees) use ($tenantKeys): void {
                foreach ($employees as $employee) {
                    if (! is_string($employee->phone) || $employee->phone === '') {
                        continue;
                    }

                    $rawTenantId = $employee->tenant_id;
                    if (! is_numeric($rawTenantId)) {
                        throw new RuntimeException('Unexpected non-numeric tenant_id in employees table.');
                    }

                    $tenantId = (int) $rawTenantId;
                    $tenantKey = $tenantKeys->get($tenantId);
                    if (! $tenantKey instanceof TenantKey) {
                        throw new RuntimeException("TenantKey not found for tenant {$tenantId}.");
                    }

                    $encryptedPhone = $tenantKey->encrypt($employee->phone);
                    $encodedPhone = json_encode([
                        'ciphertext' => base64_encode($encryptedPhone['ciphertext']),
                        'nonce' => base64_encode($encryptedPhone['nonce']),
                    ]);

                    if ($encodedPhone === false) {
                        throw new RuntimeException('Failed to encode encrypted employee phone.');
                    }

                    $phoneBlindIndex = base64_encode(
                        $tenantKey->generateBlindIndex($this->normalizePhone($employee->phone))
                    );

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'phone_enc' => $encodedPhone,
                            'phone_idx' => $phoneBlindIndex,
                        ]);
                }
            });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->index(['tenant_id', 'phone_idx']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });

        DB::table('employees')
            ->select(['id', 'tenant_id', 'phone_enc'])
            ->whereNotNull('phone_enc')
            ->orderBy('id')
            ->chunk(100, function ($employees): void {
                foreach ($employees as $employee) {
                    if (! is_string($employee->phone_enc)) {
                        continue;
                    }

                    $decodedPhone = json_decode($employee->phone_enc, true);

                    if (! is_array($decodedPhone)
                        || ! isset($decodedPhone['ciphertext'], $decodedPhone['nonce'])
                        || ! is_string($decodedPhone['ciphertext'])
                        || ! is_string($decodedPhone['nonce'])) {
                        throw new RuntimeException('Invalid encrypted employee phone payload.');
                    }

                    $ciphertext = base64_decode($decodedPhone['ciphertext'], true);
                    $nonce = base64_decode($decodedPhone['nonce'], true);

                    if (! is_string($ciphertext) || ! is_string($nonce)) {
                        throw new RuntimeException('Failed to decode encrypted employee phone payload.');
                    }

                    /** @var TenantKey $tenantKey */
                    $tenantKey = TenantKey::findOrFail($employee->tenant_id);
                    $phone = $tenantKey->decrypt($ciphertext, $nonce);

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'phone' => $phone,
                        ]);
                }
            });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'phone_idx']);
            $table->dropColumn(['phone_enc', 'phone_idx']);
        });
    }
};
