<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Console\Commands\CheckOpenTimestampStatus;
use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/**
 * Test CheckOpenTimestampStatus command.
 *
 * Tests that the command correctly detects Python, OpenTimestamp installation,
 * performs functional tests without package-index update discovery.
 *
 * @see CheckOpenTimestampStatus
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var ProcessExecutor&Mockery\MockInterface $executor */
    $executor = Mockery::mock(ProcessExecutor::class);
    app()->instance(ProcessExecutor::class, $executor);
    $this->executor = $executor;

    $executor->shouldReceive('commandExists')->byDefault()->andReturnUsing(
        static fn (string $command): bool => match ($command) {
            'python3', 'ots', 'pip' => true,
            'pip3' => false,
            default => false,
        }
    );
});

test('command checks installed client and calendar health without package-index discovery', function () {
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
    /** @var OpenTimestampService&Mockery\MockInterface $otsService */
    $otsService = Mockery::mock(OpenTimestampService::class);
    app()->instance(OpenTimestampService::class, $otsService);
    $otsService
        ->shouldReceive('submit')
        ->once()
        ->with(Mockery::pattern('/^[0-9a-f]{64}$/'))
        ->andReturn('mock-proof-data');

    $this->executor
        ->shouldReceive('execute')
        ->with(Mockery::on(static fn (array $command): bool => in_array($command[0], ['pip', 'pip3'], true)), Mockery::any(), Mockery::any())
        ->never();

    $this->artisan(CheckOpenTimestampStatus::class)
        ->expectsOutput('Checking OpenTimestamp installation...')
        ->assertExitCode(0);
});

test('command fails when functional test fails', function () {
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
    /** @var OpenTimestampService&Mockery\MockInterface $otsService */
    $otsService = Mockery::mock(OpenTimestampService::class);
    app()->instance(OpenTimestampService::class, $otsService);
    $otsService
        ->shouldReceive('submit')
        ->once()
        ->andThrow(new RuntimeException('Calendar server unavailable'));

    $this->artisan(CheckOpenTimestampStatus::class)
        ->assertExitCode(1);
});

test('command fails fast when python3 is missing', function () {
    $this->executor
        ->shouldReceive('commandExists')
        ->with('python3')
        ->andReturn(false);

    $this->artisan(CheckOpenTimestampStatus::class)
        ->expectsOutputToContain('python3')
        ->assertExitCode(1);
});

test('command fails fast when ots cli is missing', function () {
    $this->executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

    $this->executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

    $this->executor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->andReturn(false);

    $this->artisan(CheckOpenTimestampStatus::class)
        ->expectsOutputToContain('ots')
        ->assertExitCode(1);
});

test('command fails fast when opentimestamps python module is missing', function () {
    $this->executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

    $this->executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn(['exitCode' => 1, 'stdout' => '', 'stderr' => 'ModuleNotFoundError: No module named opentimestamps']);

    $this->artisan(CheckOpenTimestampStatus::class)
        ->expectsOutputToContain('opentimestamps')
        ->assertExitCode(1);
});

test('command fails fast when a required ots helper script is missing', function (string $relativePath): void {
    File::partialMock()
        ->shouldReceive('exists')
        ->with(base_path($relativePath))
        ->andReturn(false);

    $this->executor
        ->shouldReceive('execute')
        ->with(['python3', '--version'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => 'Python 3.11.2', 'stderr' => '']);

    $this->executor
        ->shouldReceive('execute')
        ->with(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5)
        ->andReturn(['exitCode' => 0, 'stdout' => '0.4.5', 'stderr' => '']);

    $this->artisan(CheckOpenTimestampStatus::class)
        ->expectsOutputToContain($relativePath)
        ->assertExitCode(1);
})->with([
    'stamp script' => ['scripts/ots-stamp-hash.py'],
    'verify script' => ['scripts/ots-verify.py'],
]);
