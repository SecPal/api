<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Console\Commands\MonitorOpenTimestamp;
use App\Contracts\ProcessExecutor;
use App\Models\Activity;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

/**
 * Test MonitorOpenTimestamp command.
 *
 * Tests the scheduled monitoring command that checks OpenTimestamp
 * health and warns about high pending proof counts.
 *
 * @see MonitorOpenTimestamp
 * @see Issue #491: Convert OpenTimestamp PHPUnit tests to Pest
 */
uses(RefreshDatabase::class);

test('command runs health check', function () {
    $executor = Mockery::mock(ProcessExecutor::class);
    $executor->shouldReceive('execute')
        ->andReturn(['stdout' => 'Python 3.11.2\n0.4.5', 'stderr' => '', 'exitCode' => 0]);
    $this->app->instance(ProcessExecutor::class, $executor);

    $otsService = Mockery::mock(OpenTimestampService::class);
    $otsService->shouldReceive('submit')
        ->andReturn(base64_encode('fake-ots-proof'));
    $this->app->instance(OpenTimestampService::class, $otsService);

    $this->artisan(MonitorOpenTimestamp::class)
        ->expectsOutput('Running OpenTimestamp health check...')
        ->expectsOutput('✓ OpenTimestamp monitoring complete')
        ->assertExitCode(0);
});

test('command logs critical error when check fails', function () {
    Log::shouldReceive('critical')
        ->once()
        ->with('OpenTimestamp health check failed', [
            'component' => 'opentimestamp',
            'check_type' => 'scheduled_monitor',
        ]);

    // Mock ProcessExecutor to simulate failed check
    $executor = Mockery::mock(ProcessExecutor::class);
    $executor->shouldReceive('execute')
        ->andReturn(['stdout' => '', 'stderr' => 'Check failed', 'exitCode' => 1]);
    $this->app->instance(ProcessExecutor::class, $executor);

    $this->artisan(MonitorOpenTimestamp::class)
        ->expectsOutput('⚠ OpenTimestamp health check FAILED')
        ->assertExitCode(1);
});

test('command warns about high pending count', function () {
    // Mock ProcessExecutor for the ots:check command
    $executor = Mockery::mock(ProcessExecutor::class);
    $executor->shouldReceive('execute')
        ->andReturn(['stdout' => 'Python 3.11.2\n0.4.5', 'stderr' => '', 'exitCode' => 0]);
    $this->app->instance(ProcessExecutor::class, $executor);

    // Mock OpenTimestampService for the ots:check functional test
    $otsService = Mockery::mock(OpenTimestampService::class);
    $otsService->shouldReceive('submit')
        ->andReturn(base64_encode('fake-ots-proof'));
    $this->app->instance(OpenTimestampService::class, $otsService);

    // Create a tenant and organizational unit for activities
    $tenant = TenantKey::factory()->create();
    $unit = OrganizationalUnit::factory()->create(['tenant_id' => $tenant->id]);

    // Create 150 activities with merkle_root but no ots_proof
    Activity::factory()->count(150)->create([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => $unit->id,
        'merkle_root' => hash('sha256', 'test'),
        'ots_proof' => null,
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with('High number of activities without OTS proof', [
            'component' => 'opentimestamp',
            'pending_count' => 150,
            'threshold' => 100,
        ]);

    $this->artisan(MonitorOpenTimestamp::class)
        ->expectsOutputToContain('150 activities without OTS proof')
        ->assertExitCode(0);
});

test('command does not warn when pending count below threshold', function () {
    // Mock ProcessExecutor for the ots:check command
    $executor = Mockery::mock(ProcessExecutor::class);
    $executor->shouldReceive('execute')
        ->andReturn(['stdout' => 'Python 3.11.2\n0.4.5', 'stderr' => '', 'exitCode' => 0]);
    $this->app->instance(ProcessExecutor::class, $executor);

    // Mock OpenTimestampService for the ots:check functional test
    $otsService = Mockery::mock(OpenTimestampService::class);
    $otsService->shouldReceive('submit')
        ->andReturn(base64_encode('fake-ots-proof'));
    $this->app->instance(OpenTimestampService::class, $otsService);

    // Create a tenant and organizational unit for activities
    $tenant = TenantKey::factory()->create();
    $unit = OrganizationalUnit::factory()->create(['tenant_id' => $tenant->id]);

    // Create only 50 activities (below threshold of 100)
    Activity::factory()->count(50)->create([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => $unit->id,
        'merkle_root' => hash('sha256', 'test'),
        'ots_proof' => null,
    ]);

    Log::shouldReceive('warning')->never();

    $this->artisan(MonitorOpenTimestamp::class)
        ->assertExitCode(0);
});
