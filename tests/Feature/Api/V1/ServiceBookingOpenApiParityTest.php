<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\BillingUnit;
use App\Enums\InvoiceState;
use App\Enums\ServiceBookingStatus;
use App\Http\Controllers\Api\V1\ServiceBookingController;
use App\Http\Requests\Api\V1\ServiceBookingRequest;
use App\Http\Resources\Api\V1\ServiceBookingResource;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('the API exposes exactly the five accepted Service Booking operations', function (): void {
    $operations = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/service-bookings')
            && ! str_contains($route->uri(), 'cost-center-allocations'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri())
            ->all())
        ->sort()->values()->all();

    expect($operations)->toBe([
        'GET v1/service-bookings',
        'GET v1/service-bookings/{serviceBooking}',
        'PATCH v1/service-bookings/{serviceBooking}',
        'POST v1/service-bookings',
        'POST v1/service-bookings/{serviceBooking}/retire',
    ]);
});

test('Service Booking implementation pins the accepted closed schemas and exact values', function (): void {
    expect(ServiceBookingRequest::AUTHORITATIVE_CONTRACTS_SHA)
        ->toBe('c530093a599eb468be89d280f965dc37dfb96579')
        ->and(ServiceBookingRequest::CREATE_FIELDS)
        ->toBe(['contract_id', 'service_date', 'quantity', 'billing_unit', 'unit_price'])
        ->and(ServiceBookingRequest::UPDATE_FIELDS)
        ->toBe(['service_date', 'quantity', 'billing_unit', 'unit_price'])
        ->and(ServiceBookingRequest::QUANTITY_PATTERN)
        ->toBe('/\A(?!0(?:\.0{1,4})?\z)(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?\z/D')
        ->and(ServiceBookingRequest::UNIT_PRICE_PATTERN)
        ->toBe('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?\z/D')
        ->and(array_column(BillingUnit::cases(), 'value'))->toBe(['hour', 'day', 'unit', 'flat'])
        ->and(array_column(InvoiceState::cases(), 'value'))->toBe(['unbilled', 'invoiced'])
        ->and(array_column(ServiceBookingStatus::cases(), 'value'))->toBe(['active', 'retired'])
        ->and(ServiceBookingResource::FIELDS)->toBe([
            'id', 'contract_id', 'service_date', 'quantity', 'billing_unit', 'unit_price',
            'currency_code', 'total', 'invoice_state', 'invoiced_at', 'status', 'retired_at',
            'created_at', 'updated_at',
        ]);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/service-bookings')
            && ! str_contains($route->uri(), 'cost-center-allocations'))
        ->mapWithKeys(fn ($route): array => [
            collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD').' '.$route->uri() => [
                'action' => $route->getActionName(),
                'permission' => collect($route->gatherMiddleware())
                    ->first(fn (string $middleware): bool => str_starts_with($middleware, 'permission:service_bookings.')),
            ],
        ]);

    expect($routes->sortKeys()->all())->toBe([
        'GET v1/service-bookings' => [
            'action' => ServiceBookingController::class.'@index',
            'permission' => 'permission:service_bookings.read',
        ],
        'GET v1/service-bookings/{serviceBooking}' => [
            'action' => ServiceBookingController::class.'@show',
            'permission' => 'permission:service_bookings.read',
        ],
        'PATCH v1/service-bookings/{serviceBooking}' => [
            'action' => ServiceBookingController::class.'@update',
            'permission' => 'permission:service_bookings.update',
        ],
        'POST v1/service-bookings' => [
            'action' => ServiceBookingController::class.'@store',
            'permission' => 'permission:service_bookings.create',
        ],
        'POST v1/service-bookings/{serviceBooking}/retire' => [
            'action' => ServiceBookingController::class.'@retire',
            'permission' => 'permission:service_bookings.retire',
        ],
    ]);
});

test('the permission catalog contains only the accepted Service Booking capabilities', function (): void {
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    expect(Permission::query()->where('name', 'like', 'service_bookings.%')->orderBy('name')->pluck('name')->all())
        ->toBe([
            'service_bookings.create',
            'service_bookings.read',
            'service_bookings.retire',
            'service_bookings.update',
        ])
        ->and(Permission::query()->whereIn('name', [
            'service_bookings.delete', 'service_bookings.invoice',
        ])->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'like', 'service_bookings:%')->exists())->toBeFalse()
        ->and(Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'like', 'service_bookings.%'))->exists())
        ->toBeFalse();
});
