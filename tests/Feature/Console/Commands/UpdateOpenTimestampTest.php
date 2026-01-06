<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\UpdateOpenTimestamp;
use App\Contracts\ProcessExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UpdateOpenTimestampTest extends TestCase
{
    use RefreshDatabase;

    private ProcessExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = Mockery::mock(ProcessExecutor::class);
        $this->app->instance(ProcessExecutor::class, $this->executor);
    }

    public function test_command_detects_current_version(): void
    {
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'list', '--outdated', '--format=json'], null, 10)
            ->andReturn(['exitCode' => 0, 'stdout' => '[]', 'stderr' => '']);

        $this->artisan(UpdateOpenTimestamp::class)
            ->expectsOutputToContain('Current version: 0.4.5')
            ->expectsOutput('✓ OpenTimestamps is already up to date')
            ->assertExitCode(0);
    }

    public function test_command_shows_available_update_in_dry_run(): void
    {
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'list', '--outdated', '--format=json'], null, 10)
            ->andReturn([
                'exitCode' => 0,
                'stdout' => json_encode([
                    ['name' => 'opentimestamps-client', 'version' => '0.4.5', 'latest_version' => '0.5.0'],
                ]),
                'stderr' => '',
            ]);

        $this->artisan(UpdateOpenTimestamp::class, ['--dry-run' => true])
            ->expectsOutputToContain('Update available: 0.4.5 → 0.5.0')
            ->expectsOutput('Dry run - no changes made')
            ->expectsOutputToContain('Would run: pip install --upgrade opentimestamps-client')
            ->assertExitCode(0);
    }

    public function test_command_exits_when_no_update_available(): void
    {
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.5.0', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'list', '--outdated', '--format=json'], null, 10)
            ->andReturn(['exitCode' => 0, 'stdout' => '[]', 'stderr' => '']);

        $this->artisan(UpdateOpenTimestamp::class)
            ->expectsOutput('✓ OpenTimestamps is already up to date')
            ->assertExitCode(0);
    }

    public function test_command_can_be_cancelled_with_no_confirmation(): void
    {
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'list', '--outdated', '--format=json'], null, 10)
            ->andReturn([
                'exitCode' => 0,
                'stdout' => json_encode([
                    ['name' => 'opentimestamps-client', 'version' => '0.4.5', 'latest_version' => '0.5.0'],
                ]),
                'stderr' => '',
            ]);

        $this->artisan(UpdateOpenTimestamp::class)
            ->expectsQuestion('Do you want to update OpenTimestamp now?', false)
            ->expectsOutput('Update cancelled')
            ->assertExitCode(0);
    }

    public function test_command_performs_upgrade_with_confirmation(): void
    {
        // Mock version check - called 3 times:
        // 1. At start of ots:update
        // 2. After pip upgrade in ots:update
        // 3. Inside ots:check command (called after upgrade)
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->times(3)
            ->andReturn(
                ['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => ''],
                ['exitCode' => 0, 'stdout' => '0.5.0', 'stderr' => ''],
                ['exitCode' => 0, 'stdout' => '0.5.0', 'stderr' => '']
            );

        // Mock python --version for ots:check
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '--version'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

        // Mock update check
        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'list', '--outdated', '--format=json'], null, 10)
            ->andReturn([
                'exitCode' => 0,
                'stdout' => json_encode([
                    ['name' => 'opentimestamps-client', 'version' => '0.4.5', 'latest_version' => '0.5.0'],
                ]),
                'stderr' => '',
            ]);

        // Mock pip upgrade execution
        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'install', '--upgrade', 'opentimestamps-client'], null, 60)
            ->andReturn([
                'exitCode' => 0,
                'stdout' => 'Successfully installed opentimestamps-client-0.5.0',
                'stderr' => '',
            ]);

        // Mock OpenTimestampService for ots:check
        $otsService = \Mockery::mock(\App\Services\OpenTimestampService::class);
        $otsService->shouldReceive('submit')->andReturn('test-proof');
        $this->app->instance(\App\Services\OpenTimestampService::class, $otsService);

        $this->artisan(UpdateOpenTimestamp::class)
            ->expectsQuestion('Do you want to update OpenTimestamp now?', true)
            ->expectsOutputToContain('Successfully updated to version 0.5.0')
            ->assertExitCode(0);
    }
}
