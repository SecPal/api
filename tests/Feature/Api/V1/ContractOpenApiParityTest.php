<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\BillingUnit;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Requests\Api\V1\ContractRequest;
use App\Http\Resources\Api\V1\ContractResource;
use Illuminate\Support\Facades\Route;

/**
 * Compact API-side parity guard for SecPal/contracts at the accepted #479 head.
 * The authoritative OpenAPI remains in SecPal/contracts; this pins only the
 * implementation facts needed to detect drift in this consumer.
 */
test('Contract HTTP implementation matches the accepted Contracts surface', function (): void {
    expect(ContractRequest::AUTHORITATIVE_CONTRACTS_SHA)
        ->toBe('97c9590b1286b78a1a0dc69af7860a14be142156')
        ->and(ContractRequest::BUSINESS_FIELDS)->toBe([
            'customer_id', 'type', 'starts_on', 'ends_on', 'billing_unit', 'unit_price', 'currency_code',
        ])
        ->and(ContractRequest::UNIT_PRICE_PATTERN)
        ->toBe('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?\z/D')
        ->and(ContractRequest::CURRENCY_CODE_PATTERN)->toBe('/\A[A-Z]{3}\z/D')
        ->and(array_column(ContractType::cases(), 'value'))
        ->toBe(['permanent', 'temporary', 'one_time', 'recurring'])
        ->and(array_column(ContractStatus::cases(), 'value'))->toBe(['active', 'retired'])
        ->and(array_column(BillingUnit::cases(), 'value'))->toBe(['hour', 'day', 'unit', 'flat'])
        ->and(ContractResource::FIELDS)->toBe([
            'id', 'customer_id', 'type', 'status', 'starts_on', 'ends_on',
            'billing_unit', 'unit_price', 'currency_code', 'retired_at', 'created_at', 'updated_at',
        ]);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/contracts'))
        ->mapWithKeys(fn ($route): array => [
            collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD').' '.$route->uri() => [
                'action' => $route->getActionName(),
                'permission' => collect($route->gatherMiddleware())
                    ->first(fn (string $middleware): bool => str_starts_with($middleware, 'permission:contracts.')),
            ],
        ]);

    expect($routes->sortKeys()->all())->toBe([
        'GET v1/contracts' => [
            'action' => ContractController::class.'@index',
            'permission' => 'permission:contracts.read',
        ],
        'GET v1/contracts/{contract}' => [
            'action' => ContractController::class.'@show',
            'permission' => 'permission:contracts.read',
        ],
        'PATCH v1/contracts/{contract}' => [
            'action' => ContractController::class.'@update',
            'permission' => 'permission:contracts.update',
        ],
        'POST v1/contracts' => [
            'action' => ContractController::class.'@store',
            'permission' => 'permission:contracts.create',
        ],
        'POST v1/contracts/{contract}/retire' => [
            'action' => ContractController::class.'@retire',
            'permission' => 'permission:contracts.retire',
        ],
    ])->and($routes->keys()->contains(fn (string $route): bool => str_contains($route, 'DELETE')))->toBeFalse()
        ->and($routes->keys()->contains(fn (string $route): bool => str_contains($route, 'reopen')))->toBeFalse();
});
