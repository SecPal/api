<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Enums\InternalCostCenterStatus;
use App\Http\Controllers\Api\V1\CostCenterAllocationController;
use App\Http\Controllers\Api\V1\InternalCostCenterController;
use App\Http\Requests\Api\V1\InternalCostCenterRequest;
use App\Http\Resources\Api\V1\CostCenterAllocationResource;
use App\Http\Resources\Api\V1\InternalCostCenterResource;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('implementation pins the accepted Contracts surface and exact seven routes', function (): void {
    expect(InternalCostCenterRequest::AUTHORITATIVE_CONTRACTS_SHA)
        ->toBe('a1bd32f3ccd634fd25d43700539cabea508ff06d')
        ->and(InternalCostCenterResource::FIELDS)->toBe([
            'id', 'code', 'name', 'status', 'inactive_at', 'created_at', 'updated_at',
        ])
        ->and(CostCenterAllocationResource::ITEM_FIELDS)
        ->toBe(['internal_cost_center_id', 'share_bps'])
        ->and(array_column(InternalCostCenterStatus::cases(), 'value'))->toBe(['active', 'inactive']);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/internal-cost-centers')
            || str_contains($route->uri(), 'cost-center-allocations'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri())
            ->all())
        ->sort()->values()->all();

    expect($routes)->toBe([
        'GET v1/internal-cost-centers',
        'GET v1/internal-cost-centers/{internalCostCenter}',
        'GET v1/service-bookings/{serviceBooking}/cost-center-allocations',
        'PATCH v1/internal-cost-centers/{internalCostCenter}',
        'POST v1/internal-cost-centers',
        'POST v1/internal-cost-centers/{internalCostCenter}/deactivate',
        'PUT v1/service-bookings/{serviceBooking}/cost-center-allocations',
    ])->and(collect(Route::getRoutes()->getRoutes())->contains(
        fn ($route): bool => $route->uri() === 'v1/cost-centers',
    ))->toBeFalse();
});

test('exact routes use dedicated controllers and exact capability middleware', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/internal-cost-centers')
            || str_contains($route->uri(), 'cost-center-allocations'))
        ->mapWithKeys(fn ($route): array => [
            collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD').' '.$route->uri() => [
                'action' => $route->getActionName(),
                'permission' => collect($route->gatherMiddleware())
                    ->first(fn (string $middleware): bool => str_starts_with($middleware, 'permission:')),
            ],
        ])->sortKeys()->all();

    expect($routes)->toBe([
        'GET v1/internal-cost-centers' => [
            'action' => InternalCostCenterController::class.'@index',
            'permission' => 'permission:internal_cost_centers.read',
        ],
        'GET v1/internal-cost-centers/{internalCostCenter}' => [
            'action' => InternalCostCenterController::class.'@show',
            'permission' => 'permission:internal_cost_centers.read',
        ],
        'GET v1/service-bookings/{serviceBooking}/cost-center-allocations' => [
            'action' => CostCenterAllocationController::class.'@show',
            'permission' => 'permission:cost_center_allocations.read',
        ],
        'PATCH v1/internal-cost-centers/{internalCostCenter}' => [
            'action' => InternalCostCenterController::class.'@update',
            'permission' => 'permission:internal_cost_centers.update',
        ],
        'POST v1/internal-cost-centers' => [
            'action' => InternalCostCenterController::class.'@store',
            'permission' => 'permission:internal_cost_centers.create',
        ],
        'POST v1/internal-cost-centers/{internalCostCenter}/deactivate' => [
            'action' => InternalCostCenterController::class.'@deactivate',
            'permission' => 'permission:internal_cost_centers.deactivate',
        ],
        'PUT v1/service-bookings/{serviceBooking}/cost-center-allocations' => [
            'action' => CostCenterAllocationController::class.'@update',
            'permission' => 'permission:cost_center_allocations.update',
        ],
    ]);
});

test('permission catalog contains exactly six new capabilities without predefined role grants', function (): void {
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $names = Permission::query()->where(function ($query): void {
        $query->where('name', 'like', 'internal_cost_centers.%')
            ->orWhere('name', 'like', 'cost_center_allocations.%');
    })->orderBy('name')->pluck('name')->all();

    expect($names)->toBe([
        'cost_center_allocations.read',
        'cost_center_allocations.update',
        'internal_cost_centers.create',
        'internal_cost_centers.deactivate',
        'internal_cost_centers.read',
        'internal_cost_centers.update',
    ])->and(Role::query()->whereHas('permissions', fn ($query) => $query->whereIn('name', $names))->exists())
        ->toBeFalse()
        ->and(Permission::query()->where('name', 'like', 'cost-centers.%')->count())->toBe(4);
});
