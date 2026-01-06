<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\CheckOpenTimestampStatus;
use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CheckOpenTimestampStatusTest extends TestCase
{
    use RefreshDatabase;

    private ProcessExecutor $executor;

    private OpenTimestampService $otsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = Mockery::mock(ProcessExecutor::class);
        $this->app->instance(ProcessExecutor::class, $this->executor);

        $this->otsService = Mockery::mock(OpenTimestampService::class);
        $this->app->instance(OpenTimestampService::class, $this->otsService);
    }

    public function test_command_detects_python_and_ots_versions(): void
    {
        // Mock ProcessExecutor for Python version
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '--version'], null, 5)
            ->andReturn([
                'exitCode' => 0,
                'stdout' => 'Python 3.11.2',
                'stderr' => '',
            ]);

        // Mock ProcessExecutor for OTS version
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn([
                'exitCode' => 0,
                'stdout' => '0.4.5',
                'stderr' => '',
            ]);

        // Mock functional test
        $this->otsService
            ->shouldReceive('submit')
            ->once()
            ->with(Mockery::pattern('/^[0-9a-f]{64}$/'))
            ->andReturn('mock-proof-data');

        $this->artisan(CheckOpenTimestampStatus::class)
            ->expectsOutput('Checking OpenTimestamp installation...')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_functional_test_fails(): void
    {
        // Mock version checks
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '--version'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

        // Mock functional test failure
        $this->otsService
            ->shouldReceive('submit')
            ->once()
            ->andThrow(new \RuntimeException('Calendar server unavailable'));

        $this->artisan(CheckOpenTimestampStatus::class)
            ->assertExitCode(1);
    }

    public function test_command_checks_for_updates_when_flag_provided(): void
    {
        // Mock version checks
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '--version'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

        // Mock functional test
        $this->otsService
            ->shouldReceive('submit')
            ->once()
            ->andReturn('mock-proof-data');

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

        $this->artisan(CheckOpenTimestampStatus::class, ['--update-check' => true])
            ->expectsOutput('Checking for updates...')
            ->assertExitCode(0);
    }

    public function test_command_handles_pip_errors_gracefully(): void
    {
        // Mock version checks
        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '--version'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

        $this->executor
            ->shouldReceive('execute')
            ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
            ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

        // Mock functional test
        $this->otsService
            ->shouldReceive('submit')
            ->once()
            ->andReturn('mock-proof-data');

        // Mock pip failure
        $this->executor
            ->shouldReceive('execute')
            ->with(['pip', 'list', '--outdated', '--format=json'], null, 10)
            ->andReturn([
                'exitCode' => 1,
                'stdout' => '',
                'stderr' => 'pip: command not found',
            ]);

        $this->artisan(CheckOpenTimestampStatus::class, ['--update-check' => true])
            ->expectsOutput('✓ All checks passed')
            ->assertExitCode(0); // Should still succeed
    }
}
