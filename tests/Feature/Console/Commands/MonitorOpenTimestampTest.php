<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\MonitorOpenTimestamp;
use App\Models\Activity;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MonitorOpenTimestampTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_health_check(): void
    {
        // The command will call ots:check internally
        $this->artisan(MonitorOpenTimestamp::class)
            ->expectsOutput('Running OpenTimestamp health check...')
            ->expectsOutput('✓ OpenTimestamp monitoring complete')
            ->assertExitCode(0);
    }

    public function test_command_logs_critical_error_when_check_fails(): void
    {
        Log::shouldReceive('critical')
            ->once()
            ->with('OpenTimestamp health check failed', [
                'component' => 'opentimestamp',
                'check_type' => 'scheduled_monitor',
            ]);

        // Mock ProcessExecutor to simulate failed check
        $executor = \Mockery::mock(\App\Contracts\ProcessExecutor::class);
        $executor->shouldReceive('execute')
            ->andReturn(['stdout' => '', 'stderr' => 'Check failed', 'exitCode' => 1]);
        $this->app->instance(\App\Contracts\ProcessExecutor::class, $executor);

        $this->artisan(MonitorOpenTimestamp::class)
            ->expectsOutput('⚠ OpenTimestamp health check FAILED')
            ->assertExitCode(1);
    }

    public function test_command_warns_about_high_pending_count(): void
    {
        // Mock ProcessExecutor for the ots:check command
        $executor = \Mockery::mock(\App\Contracts\ProcessExecutor::class);
        $executor->shouldReceive('execute')
            ->andReturn(['stdout' => 'Python 3.11.2\n0.4.5', 'stderr' => '', 'exitCode' => 0]);
        $this->app->instance(\App\Contracts\ProcessExecutor::class, $executor);

        // Mock OpenTimestampService for the ots:check functional test
        $otsService = \Mockery::mock(\App\Services\OpenTimestampService::class);
        $otsService->shouldReceive('submit')
            ->andReturn(base64_encode('fake-ots-proof'));
        $this->app->instance(\App\Services\OpenTimestampService::class, $otsService);

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
    }

    public function test_command_does_not_warn_when_pending_count_below_threshold(): void
    {
        // Mock ProcessExecutor for the ots:check command
        $executor = \Mockery::mock(\App\Contracts\ProcessExecutor::class);
        $executor->shouldReceive('execute')
            ->andReturn(['stdout' => 'Python 3.11.2\n0.4.5', 'stderr' => '', 'exitCode' => 0]);
        $this->app->instance(\App\Contracts\ProcessExecutor::class, $executor);

        // Mock OpenTimestampService for the ots:check functional test
        $otsService = \Mockery::mock(\App\Services\OpenTimestampService::class);
        $otsService->shouldReceive('submit')
            ->andReturn(base64_encode('fake-ots-proof'));
        $this->app->instance(\App\Services\OpenTimestampService::class, $otsService);

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
    }
}
