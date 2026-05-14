<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<int, TenantKey> */
    private array $tenantKeysById = [];

    public function up(): void
    {
        $this->backfillLegacyEmployeeAddresses();

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'address_street_enc',
                'address_house_number_enc',
                'address_postal_code_enc',
                'address_city_enc',
                'address_supplement_enc',
                'address_country',
                'address_state',
                'address_history',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('address_street_enc')->nullable()->after('nationalities');
            $table->text('address_house_number_enc')->nullable()->after('address_street_enc');
            $table->text('address_postal_code_enc')->nullable()->after('address_house_number_enc');
            $table->text('address_city_enc')->nullable()->after('address_postal_code_enc');
            $table->text('address_supplement_enc')->nullable()->after('address_city_enc');
            $table->string('address_country', 2)->nullable()->after('address_supplement_enc');
            $table->string('address_state', 100)->nullable()->after('address_country');
            $table->json('address_history')->nullable()->after('address_state');
        });

        $this->restoreLegacyEmployeeAddressColumns();
    }

    private function backfillLegacyEmployeeAddresses(): void
    {
        $now = Carbon::now();

        DB::table('employees')
            ->select([
                'id',
                'tenant_id',
                'address_street_enc',
                'address_house_number_enc',
                'address_postal_code_enc',
                'address_city_enc',
                'address_supplement_enc',
                'address_country',
                'address_history',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($employees) use ($now): void {
                foreach ($employees as $employee) {
                    $employeeArr = $this->objectToStringKeyedArray($employee);
                    $employeeId = $this->requireNonEmptyString($employeeArr['id'] ?? null, 'employee id');
                    $tenantId = $this->requireTenantId($employeeArr['tenant_id'] ?? null);

                    $existingAddresses = DB::table('employee_addresses')
                        ->where('employee_id', $employeeId)
                        ->orderByRaw('resided_until is null desc')
                        ->orderBy('resided_from')
                        ->get();
                    $rows = [];

                    if ($this->hasLegacyCurrentAddress($employeeArr)) {
                        $existingCurrent = $existingAddresses->first(fn (object $row): bool => $row->resided_until === null);

                        if ($existingCurrent === null) {
                            $rows[] = [
                                'id' => (string) Str::uuid(),
                                'employee_id' => $employeeId,
                                'tenant_id' => $tenantId,
                                'street_enc' => $employeeArr['address_street_enc'] ?? null,
                                'house_number_enc' => $employeeArr['address_house_number_enc'] ?? null,
                                'postal_code_enc' => $employeeArr['address_postal_code_enc'] ?? null,
                                'city_enc' => $employeeArr['address_city_enc'] ?? null,
                                'supplement_enc' => $employeeArr['address_supplement_enc'] ?? null,
                                'country' => $this->nullableString($employeeArr['address_country'] ?? null),
                                'resided_from' => null,
                                'resided_until' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        } elseif (! $this->existingCurrentAddressMatchesLegacy($existingCurrent, $employeeArr, $tenantId)) {
                            throw new RuntimeException(
                                sprintf('Cannot migrate mixed employee address state for employee %s.', $employeeId)
                            );
                        }
                    }

                    foreach ($this->decodeLegacyAddressHistory($employeeArr['address_history'] ?? null) as $historyRow) {
                        if (! $this->legacyHistoryRowHasContent($historyRow)) {
                            continue;
                        }

                        if ($this->legacyHistoricalRowAlreadyExists(
                            $existingAddresses->filter(fn (object $row): bool => $row->resided_until !== null),
                            $tenantId,
                            $historyRow
                        )) {
                            continue;
                        }

                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'employee_id' => $employeeId,
                            'tenant_id' => $tenantId,
                            'street_enc' => $this->encryptLegacyPlaintext($tenantId, $historyRow['street'] ?? null),
                            'house_number_enc' => $this->encryptLegacyPlaintext($tenantId, $historyRow['house_number'] ?? null),
                            'postal_code_enc' => $this->encryptLegacyPlaintext($tenantId, $historyRow['postal_code'] ?? null),
                            'city_enc' => $this->encryptLegacyPlaintext($tenantId, $historyRow['city'] ?? null),
                            'supplement_enc' => $this->encryptLegacyPlaintext($tenantId, $historyRow['supplement'] ?? null),
                            'country' => $this->nullableString($historyRow['country'] ?? null),
                            'resided_from' => $this->nullableString($historyRow['resided_from'] ?? null),
                            'resided_until' => $this->nullableString($historyRow['resided_until'] ?? null),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($rows === []) {
                        continue;
                    }

                    DB::table('employee_addresses')->insert($rows);
                }
            });
    }

    private function restoreLegacyEmployeeAddressColumns(): void
    {
        collect(DB::table('employee_addresses')
            ->select([
                'employee_id',
                'tenant_id',
                'street_enc',
                'house_number_enc',
                'postal_code_enc',
                'city_enc',
                'supplement_enc',
                'country',
                'resided_from',
                'resided_until',
            ])
            ->orderBy('employee_id')
            ->orderByRaw('resided_until is null desc')
            ->orderBy('resided_from')
            ->get()
            ->groupBy('employee_id'))
            ->each(function (Collection $rows, string $employeeId): void {
                $current = $rows->first(fn (object $row): bool => $row->resided_until === null);
                $currentArr = $current !== null ? $this->objectToStringKeyedArray($current) : null;

                $history = $rows
                    ->filter(fn (object $row): bool => $row->resided_until !== null)
                    ->map(function (object $row): array {
                        $r = $this->objectToStringKeyedArray($row);
                        $tenantId = $this->requireTenantId($r['tenant_id'] ?? null);

                        return [
                            'street' => $this->decryptLegacyCiphertext($tenantId, $r['street_enc'] ?? null),
                            'house_number' => $this->decryptLegacyCiphertext($tenantId, $r['house_number_enc'] ?? null),
                            'postal_code' => $this->decryptLegacyCiphertext($tenantId, $r['postal_code_enc'] ?? null),
                            'city' => $this->decryptLegacyCiphertext($tenantId, $r['city_enc'] ?? null),
                            'supplement' => $this->decryptLegacyCiphertext($tenantId, $r['supplement_enc'] ?? null),
                            'country' => $this->nullableString($r['country'] ?? null),
                            'resided_from' => $r['resided_from'] ?? null,
                            'resided_until' => $r['resided_until'] ?? null,
                        ];
                    })
                    ->values()
                    ->all();

                DB::table('employees')
                    ->where('id', $employeeId)
                    ->update([
                        'address_street_enc' => $currentArr['street_enc'] ?? null,
                        'address_house_number_enc' => $currentArr['house_number_enc'] ?? null,
                        'address_postal_code_enc' => $currentArr['postal_code_enc'] ?? null,
                        'address_city_enc' => $currentArr['city_enc'] ?? null,
                        'address_supplement_enc' => $currentArr['supplement_enc'] ?? null,
                        'address_country' => $currentArr['country'] ?? null,
                        'address_state' => null,
                        'address_history' => $history === []
                            ? null
                            : json_encode($history, JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function hasLegacyCurrentAddress(array $employee): bool
    {
        return $this->nullableString($employee['address_street_enc'] ?? null) !== null
            || $this->nullableString($employee['address_house_number_enc'] ?? null) !== null
            || $this->nullableString($employee['address_postal_code_enc'] ?? null) !== null
            || $this->nullableString($employee['address_city_enc'] ?? null) !== null
            || $this->nullableString($employee['address_supplement_enc'] ?? null) !== null
            || $this->nullableString($employee['address_country'] ?? null) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeLegacyAddressHistory(mixed $addressHistory): array
    {
        if (is_string($addressHistory) && $addressHistory !== '') {
            $decoded = json_decode($addressHistory, true);
            if (! is_array($decoded)) {
                throw new RuntimeException(
                    'Legacy address_history column contains invalid JSON; manual repair required before migration can proceed.',
                );
            }

            $items = array_values(array_filter($decoded, 'is_array'));

            return array_map(
                fn (array $item): array => $this->arrayOnlyStringKeys($item),
                $items,
            );
        }

        if (is_array($addressHistory)) {
            $items = array_values(array_filter($addressHistory, 'is_array'));

            return array_map(
                fn (array $item): array => $this->arrayOnlyStringKeys($item),
                $items,
            );
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function legacyHistoryRowHasContent(array $row): bool
    {
        return $this->nullableString($row['street'] ?? null) !== null
            || $this->nullableString($row['house_number'] ?? null) !== null
            || $this->nullableString($row['postal_code'] ?? null) !== null
            || $this->nullableString($row['city'] ?? null) !== null
            || $this->nullableString($row['supplement'] ?? null) !== null
            || $this->nullableString($row['country'] ?? null) !== null
            || $this->nullableString($row['resided_from'] ?? null) !== null
            || $this->nullableString($row['resided_until'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function existingCurrentAddressMatchesLegacy(object $existingCurrent, array $employee, int $tenantId): bool
    {
        $current = $this->objectToStringKeyedArray($existingCurrent);

        return $this->decryptLegacyCiphertext($tenantId, $current['street_enc'] ?? null) === $this->decryptLegacyCiphertext($tenantId, $employee['address_street_enc'] ?? null)
            && $this->decryptLegacyCiphertext($tenantId, $current['house_number_enc'] ?? null) === $this->decryptLegacyCiphertext($tenantId, $employee['address_house_number_enc'] ?? null)
            && $this->decryptLegacyCiphertext($tenantId, $current['postal_code_enc'] ?? null) === $this->decryptLegacyCiphertext($tenantId, $employee['address_postal_code_enc'] ?? null)
            && $this->decryptLegacyCiphertext($tenantId, $current['city_enc'] ?? null) === $this->decryptLegacyCiphertext($tenantId, $employee['address_city_enc'] ?? null)
            && $this->decryptLegacyCiphertext($tenantId, $current['supplement_enc'] ?? null) === $this->decryptLegacyCiphertext($tenantId, $employee['address_supplement_enc'] ?? null)
            && $this->nullableString($current['country'] ?? null) === $this->nullableString($employee['address_country'] ?? null);
    }

    /**
     * @param  iterable<object>  $existingHistoricalRows
     * @param  array<string, mixed>  $historyRow
     */
    private function legacyHistoricalRowAlreadyExists(
        iterable $existingHistoricalRows,
        int $tenantId,
        array $historyRow
    ): bool {
        foreach ($existingHistoricalRows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $r = $this->objectToStringKeyedArray($row);

            if (
                $this->decryptLegacyCiphertext($tenantId, $r['street_enc'] ?? null) === $this->nullableString($historyRow['street'] ?? null)
                && $this->decryptLegacyCiphertext($tenantId, $r['house_number_enc'] ?? null) === $this->nullableString($historyRow['house_number'] ?? null)
                && $this->decryptLegacyCiphertext($tenantId, $r['postal_code_enc'] ?? null) === $this->nullableString($historyRow['postal_code'] ?? null)
                && $this->decryptLegacyCiphertext($tenantId, $r['city_enc'] ?? null) === $this->nullableString($historyRow['city'] ?? null)
                && $this->decryptLegacyCiphertext($tenantId, $r['supplement_enc'] ?? null) === $this->nullableString($historyRow['supplement'] ?? null)
                && $this->nullableString($r['country'] ?? null) === $this->nullableString($historyRow['country'] ?? null)
                && ($r['resided_from'] ?? null) === $this->nullableString($historyRow['resided_from'] ?? null)
                && ($r['resided_until'] ?? null) === $this->nullableString($historyRow['resided_until'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    private function encryptLegacyPlaintext(int $tenantId, mixed $value): ?string
    {
        $plaintext = $this->nullableString($value);

        if ($plaintext === null) {
            return null;
        }

        $tenant = $this->tenantKeyFor($tenantId);
        $encrypted = $tenant->encrypt($plaintext);

        return json_encode([
            'ciphertext' => base64_encode($encrypted['ciphertext']),
            'nonce' => base64_encode($encrypted['nonce']),
        ], JSON_THROW_ON_ERROR);
    }

    private function decryptLegacyCiphertext(int $tenantId, mixed $value): ?string
    {
        $ciphertext = $this->nullableString($value);

        if ($ciphertext === null) {
            return null;
        }

        $decoded = json_decode($ciphertext, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! isset($decoded['ciphertext'], $decoded['nonce'])) {
            return null;
        }

        $tenant = $this->tenantKeyFor($tenantId);

        $cipherRaw = $decoded['ciphertext'];
        $nonceRaw = $decoded['nonce'];
        if (! is_string($cipherRaw) || ! is_string($nonceRaw)) {
            return null;
        }

        $decodedCiphertext = base64_decode($cipherRaw, true);
        $decodedNonce = base64_decode($nonceRaw, true);

        if ($decodedCiphertext === false || $decodedNonce === false) {
            return null;
        }

        return $tenant->decrypt($decodedCiphertext, $decodedNonce);
    }

    private function tenantKeyFor(int $tenantId): TenantKey
    {
        return $this->tenantKeysById[$tenantId]
            ??= TenantKey::query()->findOrFail($tenantId);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectToStringKeyedArray(object $row): array
    {
        $raw = (array) $row;
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $row
     * @return array<string, mixed>
     */
    private function arrayOnlyStringKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function requireNonEmptyString(mixed $value, string $label): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new RuntimeException("Invalid {$label} during legacy address migration.");
    }

    private function requireTenantId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException('Invalid tenant id during legacy address migration.');
    }
};
