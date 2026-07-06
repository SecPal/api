<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Http\Controllers\HealthController;
use App\Services\RuntimeHeartbeatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('returns 503 instead of 500 when database connectivity throws a non-exception throwable', function (): void {
    DB::partialMock()
        ->shouldReceive('connection->getPdo')
        ->once()
        ->andThrow(new TypeError('Database password must be a string.'));

    $tenantKey = Mockery::mock('alias:App\Models\TenantKey');
    $tenantKey->shouldReceive('count')->once()->andReturn(1);
    $tenantKey->shouldReceive('getKekPath')->once()->andReturn('/tmp/secpal-test-kek.key');

    File::shouldReceive('exists')->once()->with('/tmp/secpal-test-kek.key')->andReturn(true);
    File::shouldReceive('isReadable')->once()->with('/tmp/secpal-test-kek.key')->andReturn(true);

    $runtimeHeartbeatService = Mockery::mock(RuntimeHeartbeatService::class);
    $runtimeHeartbeatService->shouldReceive('schedulerReadiness')->once()->andReturn(['healthy' => true]);
    $runtimeHeartbeatService->shouldReceive('queueReadiness')->once()->andReturn([]);

    $response = app(HealthController::class)->ready($runtimeHeartbeatService);

    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'not_ready',
        ]);
});
