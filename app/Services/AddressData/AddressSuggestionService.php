<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\AddressData;

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Support\AddressSearchNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class AddressSuggestionService
{
    private const CACHE_TTL_SECONDS = 3600;

    /** @deprecated Old keys cached full models and break after deploy (see activeImport). */
    private const CACHE_PREFIX = 'address_data:active_import:';

    /** Cache integer import ids only; bump suffix if stored shape changes. */
    private const CACHE_PREFIX_IMPORT_ID = 'address_data:active_import_id:v1:';

    public function forgetActiveImportCache(string $countryCode = 'DE'): void
    {
        Cache::forget(self::CACHE_PREFIX.$countryCode);
        Cache::forget(self::CACHE_PREFIX_IMPORT_ID.$countryCode);
    }

    public function activeImport(string $countryCode = 'DE'): ?AddressDataImport
    {
        // Cache the primary key only. Serializing Eloquent models in Cache::remember()
        // can yield __PHP_Incomplete_Class on unserialize (500 TypeError) in production.
        $id = Cache::remember(
            self::CACHE_PREFIX_IMPORT_ID.$countryCode,
            self::CACHE_TTL_SECONDS,
            function () use ($countryCode): ?int {
                $row = AddressDataImport::query()
                    ->where('country_code', $countryCode)
                    ->where('status', AddressDataImport::STATUS_SUCCEEDED)
                    ->whereNotNull('activated_at')
                    ->orderByDesc('id')
                    ->first();

                return $row?->id;
            },
        );

        if ($id === null) {
            return null;
        }

        $import = AddressDataImport::query()->find($id);
        if ($import === null) {
            $this->forgetActiveImportCache($countryCode);
        }

        return $import;
    }

    /**
     * @return Collection<int, AddressStreet>
     */
    public function suggestStreets(
        string $countryCode,
        ?string $name,
        ?string $postalCodePrefix,
        ?string $locality,
        int $limit,
    ): Collection {
        $import = $this->activeImport($countryCode);
        if ($import === null) {
            return AddressStreet::query()->whereRaw('0 = 1')->get();
        }

        $query = AddressStreet::query()->where('import_id', $import->id);

        if ($postalCodePrefix !== null && $postalCodePrefix !== '') {
            $query->where('postal_code', 'like', $postalCodePrefix.'%');
        }

        if ($name !== null && $name !== '') {
            $this->applySearchPrefixConstraint($query, 'name', $name);
        }

        if ($locality !== null && $locality !== '') {
            $this->applySearchPrefixConstraint($query, 'locality', $locality);
        }

        $this->orderStreetResults($query, $postalCodePrefix, $name, $locality);

        return $query->limit($limit)->get();
    }

    /**
     * @return Collection<int, array{postal_code: string, locality: string}>
     */
    public function suggestLocalities(
        string $countryCode,
        ?string $postalCodePrefix,
        ?string $locality,
        int $limit,
    ): Collection {
        $import = $this->activeImport($countryCode);
        if ($import === null) {
            return new Collection;
        }

        // Aggregate distinct (postal_code, locality) pairs *before* applying LIMIT.
        // Rows are per street; without grouping, LIMIT can consume the budget with many
        // streets from one PLZ so other prefixes never surface (e.g. only 42103 while typing 42109).
        $q = AddressStreet::query()
            ->where('import_id', $import->id)
            ->select(['postal_code', 'locality']);

        if ($postalCodePrefix !== null && $postalCodePrefix !== '') {
            $q->where('postal_code', 'like', $postalCodePrefix.'%');
        }

        if ($locality !== null && $locality !== '') {
            $this->applySearchPrefixConstraint($q, 'locality', $locality);
        }

        $q->groupBy('postal_code', 'locality')
            ->orderBy('postal_code')
            ->orderBy('locality')
            ->limit($limit);

        return $q->get()
            ->map(fn (AddressStreet $row): array => [
                'postal_code' => $row->postal_code,
                'locality' => $row->locality,
            ])
            ->values();
    }

    /**
     * @param  Builder<AddressStreet>  $query
     */
    private function orderStreetResults(
        Builder $query,
        ?string $postalCodePrefix,
        ?string $name,
        ?string $locality,
    ): void {
        $query->orderBy('postal_code');

        if ($locality !== null && $locality !== '') {
            $nl = AddressSearchNormalizer::normalize($locality);
            $nlAscii = AddressSearchNormalizer::normalizeAsciiFallback($locality);
            $query->orderByRaw(
                'CASE WHEN locality_search = ? OR locality_search_ascii = ? THEN 0 ELSE 1 END',
                [$nl, $nlAscii]
            );
        }

        if ($name !== null && $name !== '') {
            $nn = AddressSearchNormalizer::normalize($name);
            $nnAscii = AddressSearchNormalizer::normalizeAsciiFallback($name);
            $query->orderByRaw(
                'CASE WHEN name_search = ? OR name_search_ascii = ? THEN 0 ELSE 1 END',
                [$nn, $nnAscii]
            );
        }

        $query->orderBy('name_search')->orderBy('locality_search');
    }

    /**
     * @param  Builder<AddressStreet>  $query
     */
    private function applySearchPrefixConstraint(Builder $query, string $fieldPrefix, string $value): void
    {
        $normalized = AddressSearchNormalizer::normalize($value);
        $asciiFallback = AddressSearchNormalizer::normalizeAsciiFallback($value);
        $normalizedColumn = $fieldPrefix.'_search';
        $asciiColumn = $fieldPrefix.'_search_ascii';

        $query->where(function (Builder $nestedQuery) use (
            $normalized,
            $asciiFallback,
            $normalizedColumn,
            $asciiColumn
        ): void {
            $nestedQuery->where($normalizedColumn, 'like', $normalized.'%');
            $nestedQuery->orWhere($asciiColumn, 'like', $asciiFallback.'%');
        });
    }
}
