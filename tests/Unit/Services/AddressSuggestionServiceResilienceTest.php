<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\AddressData\AddressSuggestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

test('active import returns null instead of throwing when address_data_imports table is missing', function (): void {
    $service = new AddressSuggestionService(
        activeImportQueryResolver: function (string $countryCode): Builder {
            throw new QueryException(
                connectionName: 'pgsql',
                sql: 'select * from "address_data_imports" where "country_code" = ?',
                bindings: [$countryCode],
                previous: new Exception('SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "address_data_imports" does not exist'),
            );
        },
    );

    expect($service->activeImport('DE'))->toBeNull();
});

test('service no longer exposes the deprecated active import cache prefix constant', function (): void {
    $constants = (new ReflectionClass(AddressSuggestionService::class))->getConstants();

    expect($constants)->not->toHaveKey('CACHE_PREFIX');
});
