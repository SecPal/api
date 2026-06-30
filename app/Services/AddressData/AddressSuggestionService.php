<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\AddressData;

use App\Models\AddressDataImport;
use App\Models\AddressStreet;
use App\Support\AddressSearchNormalizer;
use App\Support\LikePattern;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class AddressSuggestionService
{
    private const CACHE_TTL_SECONDS = 3600;

    /** Cache integer import ids only; bump suffix if stored shape changes. */
    private const CACHE_PREFIX_IMPORT_ID = 'address_data:active_import_id:v1:';

    /**
     * @param  (Closure(string): Builder<AddressDataImport>)|null  $activeImportQueryResolver
     */
    public function __construct(
        private readonly ?Closure $activeImportQueryResolver = null,
    ) {}

    public function forgetActiveImportCache(string $countryCode = 'DE'): void
    {
        Cache::forget(self::CACHE_PREFIX_IMPORT_ID.$countryCode);
    }

    public function activeImport(string $countryCode = 'DE'): ?AddressDataImport
    {
        try {
            return $this->resolveActiveImport($countryCode);
        } catch (QueryException $exception) {
            if ($this->isMissingAddressDataImportsTable($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function resolveActiveImport(string $countryCode): ?AddressDataImport
    {
        // Cache the primary key only. Serializing Eloquent models in Cache::remember()
        // can yield __PHP_Incomplete_Class on unserialize (500 TypeError) in production.
        $id = Cache::remember(
            self::CACHE_PREFIX_IMPORT_ID.$countryCode,
            self::CACHE_TTL_SECONDS,
            fn (): ?int => $this->activeImportQuery($countryCode)->first()?->id,
        );

        if ($id === null) {
            return null;
        }

        /** @var AddressDataImport|null $import */
        $import = $this->activeImportQuery($countryCode)
            ->whereKey($id)
            ->first();
        if ($import !== null) {
            return $import;
        }

        $this->forgetActiveImportCache($countryCode);

        /** @var AddressDataImport|null */
        return $this->activeImportQuery($countryCode)->first();
    }

    private function isMissingAddressDataImportsTable(QueryException $exception): bool
    {
        $message = $exception->getMessage();
        $code = (string) $exception->getCode();

        if (! str_contains($message, 'address_data_imports')) {
            return false;
        }

        return in_array($code, ['42P01', '42S02'], true)
            || str_contains($message, 'Undefined table')
            || str_contains($message, 'Base table or view not found')
            || str_contains($message, 'no such table');
    }

    /**
     * @return Builder<AddressDataImport>
     */
    private function activeImportQuery(string $countryCode): Builder
    {
        if ($this->activeImportQueryResolver !== null) {
            /** @var Builder<AddressDataImport> */
            return ($this->activeImportQueryResolver)($countryCode);
        }

        return AddressDataImport::query()
            ->where('country_code', $countryCode)
            ->where('status', AddressDataImport::STATUS_SUCCEEDED)
            ->whereNotNull('activated_at')
            ->orderByDesc('id');
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
        ?AddressDataImport $import = null,
    ): Collection {
        $import ??= $this->activeImport($countryCode);
        if ($import === null) {
            return AddressStreet::query()->whereRaw('0 = 1')->get();
        }

        $query = AddressStreet::query()->where('import_id', $import->id);

        if ($postalCodePrefix !== null && $postalCodePrefix !== '') {
            $this->applyPostalCodePrefixConstraint($query, $postalCodePrefix);
        }

        if ($name !== null && $name !== '') {
            $this->applySearchPrefixConstraint($query, 'name', $name);
        }

        if ($locality !== null && $locality !== '') {
            $this->applySearchPrefixConstraint($query, 'locality', $locality);
        }

        $this->orderStreetResults($query, $name, $postalCodePrefix, $locality);

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
        ?AddressDataImport $import = null,
    ): Collection {
        $import ??= $this->activeImport($countryCode);
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
            $this->applyPostalCodePrefixConstraint($q, $postalCodePrefix);
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
        ?string $name,
        ?string $postalCodePrefix,
        ?string $locality,
    ): void {
        if ($postalCodePrefix !== null && $postalCodePrefix !== '') {
            $query->orderByRaw(
                'CASE WHEN postal_code = ? THEN 0 ELSE 1 END',
                [$postalCodePrefix]
            );
        }

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

        $query->orderBy('postal_code')
            ->orderBy('name_search')
            ->orderBy('locality_search');
    }

    /**
     * @param  Builder<AddressStreet>  $query
     */
    private function applySearchPrefixConstraint(Builder $query, string $fieldPrefix, string $value): void
    {
        $normalized = AddressSearchNormalizer::normalize($value);
        $asciiFallback = AddressSearchNormalizer::normalizeAsciiFallback($value);
        $normalizedPattern = LikePattern::escape($normalized).'%';
        $asciiFallbackPattern = LikePattern::escape($asciiFallback).'%';
        $normalizedColumn = $fieldPrefix.'_search';
        $asciiColumn = $fieldPrefix.'_search_ascii';

        $query->where(function (Builder $nestedQuery) use (
            $normalizedPattern,
            $asciiFallbackPattern,
            $normalizedColumn,
            $asciiColumn
        ): void {
            $nestedQuery->where($normalizedColumn, 'like', $normalizedPattern);
            $nestedQuery->orWhere($asciiColumn, 'like', $asciiFallbackPattern);
        });
    }

    /**
     * @param  Builder<AddressStreet>  $query
     */
    private function applyPostalCodePrefixConstraint(Builder $query, string $postalCodePrefix): void
    {
        $query->where('postal_code', 'like', LikePattern::escape($postalCodePrefix).'%');
    }
}
