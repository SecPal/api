<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Console\Commands\CheckOpenTimestampStatus;
use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test CheckOpenTimestampStatus command.
 *
 * Tests that the command correctly detects Python, OpenTimestamp installation,
 * performs functional tests, and optionally checks for updates.
 *
 * @see App\Console\Commands\CheckOpenTimestampStatus
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
uses(RefreshDatabase::class);

test('command detects python and ots versions', function () {
    // Mock ProcessExecutor for Python version
    /** @var ProcessExecutor&\Mockery\MockInterface $executor */
    $executor = $this->mock(ProcessExecutor::class);
    $executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn([
            'exitCode' => 0,
            'stdout' => 'Python 3.11.2',
            'stderr' => '',
        ]);

    // Mock ProcessExecutor for OTS version
    $executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn([
            'exitCode' => 0,
            'stdout' => '0.4.5',
            'stderr' => '',
        ]);

    // Mock functional test
    /** @var OpenTimestampService&\Mockery\MockInterface $otsService */
    $otsService = $this->mock(OpenTimestampService::class);
    $otsService
        ->shouldReceive('submit')
        ->once()
        ->with(Mockery::pattern('/^[0-9a-f]{64}$/'))
        ->andReturn('mock-proof-data');

    $this->artisan(CheckOpenTimestampStatus::class)
        ->expectsOutput('Checking OpenTimestamp installation...')
        ->assertExitCode(0);
});

test('command fails when functional test fails', function () {
    /** @var ProcessExecutor&\Mockery\MockInterface $executor */
    $executor = $this->mock(ProcessExecutor::class);

    // Mock version checks
    $executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

    $executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

    // Mock functional test failure
    /** @var OpenTimestampService&\Mockery\MockInterface $otsService */
    $otsService = $this->mock(OpenTimestampService::class);
    $otsService
        ->shouldReceive('submit')
        ->once()
        ->andThrow(new \RuntimeException('Calendar server unavailable'));

    $this->artisan(CheckOpenTimestampStatus::class)
        ->assertExitCode(1);
});

test('command checks for updates when flag provided', function () {
    /** @var ProcessExecutor&\Mockery\MockInterface $executor */
    $executor = $this->mock(ProcessExecutor::class);

    // Mock version checks
    $executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

    $executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

    // Mock functional test
    /** @var OpenTimestampService&\Mockery\MockInterface $otsService */
    $otsService = $this->mock(OpenTimestampService::class);
    $otsService
        ->shouldReceive('submit')
        ->once()
        ->andReturn('mock-proof-data');

    // Mock update check
    $executor
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
});

test('command handles pip errors gracefully', function () {
    /** @var ProcessExecutor&\Mockery\MockInterface $executor */
    $executor = $this->mock(ProcessExecutor::class);

    // Mock version checks
    $executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

    $executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

    // Mock functional test
    /** @var OpenTimestampService&\Mockery\MockInterface $otsService */
    $otsService = $this->mock(OpenTimestampService::class);
    $otsService
        ->shouldReceive('submit')
        ->once()
        ->andReturn('mock-proof-data');

    // Mock pip failure
    $executor
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
});
