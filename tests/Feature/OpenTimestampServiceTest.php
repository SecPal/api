<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;

/**
 * Test OpenTimestamp service integration.
 *
 * Tests submission and upgrade of timestamps via REST API.
 *
 * @see OpenTimestampService
 */
/**
 * @property ProcessExecutor $mockExecutor
 * @property OpenTimestampService $service
 */
uses()->group('feature');

beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->mockExecutor->shouldReceive('commandExists')
        ->with('python3')
        ->andReturn(true)
        ->byDefault();
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

test('submit creates pending proof', function () {
    // Arrange: Mock Python script execution
    $merkleRoot = hash('sha256', 'test-merkle-root');

    $mockProof = hex2bin(
        '00'. // OpSHA256
            '04f0'. // OpPrepend
            bin2hex('alice.btc.calendar.opentimestamps.org')
    );

    $this->mockExecutor->shouldReceive('execute')
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 3
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-stamp-hash.py')
                && $command[2] === $merkleRoot
                && $stdin === null
                && $timeout === 15;
        })
        ->once()
        ->andReturn(['exitCode' => 0, 'stdout' => $mockProof, 'stderr' => '']);

    // Act: Submit timestamp
    $proof = $this->service->submit($merkleRoot);

    // Assert: Proof created
    expect($proof)->not->toBeEmpty();
    expect($proof)->toBeString();
    expect(strlen($proof))->toBeGreaterThan(20); // Minimal proof size
});

test('submit fails when every calendar submission fails', function () {
    // Arrange: Python script fails only when no calendar accepts the submission.
    $merkleRoot = hash('sha256', 'test-merkle-root');

    // Mock the script's all-calendars-failed exit path.
    $this->mockExecutor->shouldReceive('execute')
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 3
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-stamp-hash.py')
                && $command[2] === $merkleRoot
                && $stdin === null
                && $timeout === 15;
        })
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Error: Failed to submit to at least 1 calendar server',
        ]);

    // Act & Assert: Should throw exception
    expect(fn () => $this->service->submit($merkleRoot))
        ->toThrow(
            RuntimeException::class,
            'Failed to submit timestamp: OTS submission script failed with exit code 1: Error: Failed to submit to at least 1 calendar server',
        );
});

test('upgrade returns null if not yet confirmed', function () {
    // Arrange: Create minimal pending proof
    $pendingProof = createPendingProof();

    // Mock: CLI upgrade command runs but proof not ready yet
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) {
            return count($command) === 3
                && $command[0] === 'ots'
                && $command[1] === 'upgrade'
                && dirname($command[2]) === sys_get_temp_dir()
                && str_starts_with(basename($command[2]), 'ots_upgrade_')
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturnUsing(function ($command) use ($pendingProof) {
            // Simulate CLI: Proof file unchanged (no upgrade available)
            file_put_contents($command[2], $pendingProof);

            return [
                'exitCode' => 0,
                'stdout' => 'No upgrade available yet',
                'stderr' => '',
            ];
        });

    // Act: Attempt upgrade
    $upgradedProof = $this->service->upgrade($pendingProof);

    // Assert: No upgrade available
    expect($upgradedProof)->toBeNull();
});

test('upgrade returns confirmed proof when available', function () {
    // Arrange: Create pending proof
    $pendingProof = createPendingProof();

    // Mock confirmed proof with Bitcoin attestation
    $confirmedProof = createConfirmedProof();

    // Mock: CLI upgrade command succeeds and modifies file
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) {
            return count($command) === 3
                && $command[0] === 'ots'
                && $command[1] === 'upgrade'
                && dirname($command[2]) === sys_get_temp_dir()
                && str_starts_with(basename($command[2]), 'ots_upgrade_')
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturnUsing(function ($command) use ($confirmedProof) {
            // Simulate CLI: Write upgraded proof to file
            file_put_contents($command[2], $confirmedProof);

            return [
                'exitCode' => 0,
                'stdout' => 'Success! Timestamp upgraded',
                'stderr' => '',
            ];
        });

    // Act: Upgrade
    $upgraded = $this->service->upgrade($pendingProof);

    // Assert: Proof upgraded
    expect($upgraded)->not->toBeNull();
    expect($upgraded)->not->toBe($pendingProof);
    // Check that proof contains Bitcoin attestation signature bytes
    expect($upgraded)->toContain("\x05\x88\x96\x0d");
});

test('verify returns false for invalid proof', function () {
    // Arrange: Invalid proof
    $invalidProof = 'invalid-proof-data';
    $merkleRoot = hash('sha256', 'test');

    // Mock: Python script returns failure
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 2,
            'stdout' => '',
            'stderr' => 'Error: Invalid proof',
        ]);

    // Act & Assert
    $result = $this->service->verify($invalidProof, $merkleRoot);

    expect($result)->toBeFalse();
});

test('verify returns false when python not available', function () {
    // Arrange: python3 not installed
    $merkleRoot = hash('sha256', 'test-root');
    $merkleRootBytes = hex2bin($merkleRoot);

    // Create proof: merkle root (32 bytes) + Bitcoin attestation signature
    $confirmedProof = $merkleRootBytes."\x05\x88\x96\x0d\x73\xd7\x19\x01\x03\xe3\x93\x10";

    // Mock: python3 not available
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('python3')
        ->once()
        ->andReturn(false);

    // Act: Verify
    $result = $this->service->verify($confirmedProof, $merkleRoot);

    // Assert: Should return false when python3 not installed
    expect($result)->toBeFalse('verify() should return false when python3 not installed');
});

test('verify checks for bitcoin attestation', function () {
    // Arrange: Proof without Bitcoin attestation (still pending)
    $merkleRoot = hash('sha256', 'test-root');
    $pendingProof = createPendingProof();

    // Mock: Python script returns "not yet confirmed" error
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Pending attestation, not yet confirmed',
        ]);

    // Act & Assert: Should return false (no Bitcoin attestation)
    $result = $this->service->verify($pendingProof, $merkleRoot);
    expect($result)->toBeFalse();
});

/**
 * Create minimal pending proof for testing.
 */
function createPendingProof(): string
{
    // OTS proof format: OpSHA256 + OpPrepend(calendar) + PendingAttestation
    return hex2bin(
        '00'. // OpSHA256
            '04'. // OpPrepend
            '04'. // 4 bytes length
            bin2hex('test').
            '83'. // PendingAttestation
            'ddf405'. // Calendar URL length
            bin2hex('https://alice.btc.calendar.opentimestamps.org')
    );
}

/**
 * Create confirmed proof with Bitcoin attestation for testing.
 */
function createConfirmedProof(?string $merkleRoot = null): string
{
    // OTS proof format: Operations + BitcoinBlockHeaderAttestation
    return hex2bin(
        '00'. // OpSHA256
            '0588960d73d7190103'. // Bitcoin block 428648 attestation
            'e39310' // Block height encoded
    );
}
