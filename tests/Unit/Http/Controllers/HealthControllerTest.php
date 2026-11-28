<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\HealthController;
use App\Services\RuntimeHeartbeatService;
use App\Services\TenantKekRegistry;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(ResponseFactory::class);

    DB::shouldReceive('getConnections')->andReturn([]);
    DB::shouldReceive('transactionLevel')->once()->andReturn(0);
});

it('returns 503 instead of 500 when database connectivity throws a non-exception throwable', function (): void {
    DB::shouldReceive('connection')
        ->once()
        ->andThrow(new TypeError('Database password must be a string.'));
    DB::shouldReceive('transaction')->once()->andThrow(new TypeError('Tenant key database unavailable.'));

    $response = app(HealthController::class)->ready(healthyRuntimeHeartbeatService(), app(TenantKekRegistry::class));

    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'not_ready',
        ]);
});

it('returns 503 instead of 500 when tenant-key checks throw a non-exception throwable', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getPdo')->once()->andReturn(new PDO('sqlite::memory:'));

    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new TypeError('Tenant key database connection must be configured.'));

    $response = app(HealthController::class)->ready(healthyRuntimeHeartbeatService(), app(TenantKekRegistry::class));

    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'not_ready',
        ]);
});

function healthyRuntimeHeartbeatService(): RuntimeHeartbeatService
{
    $runtimeHeartbeatService = Mockery::mock(RuntimeHeartbeatService::class);
    $runtimeHeartbeatService->shouldReceive('schedulerReadiness')->once()->andReturn(['healthy' => true]);
    $runtimeHeartbeatService->shouldReceive('queueReadiness')->once()->andReturn([]);

    return $runtimeHeartbeatService;
}
