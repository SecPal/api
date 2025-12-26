<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use InvalidArgumentException;

/**
 * Unit tests for OpenTimestamp CLI-based proof verification.
 *
 * Tests the secure implementation using external `ots verify` CLI tool.
 * Mocks ProcessExecutor to avoid dependency on actual ots-cli installation.
 *
 * @see App\Services\OpenTimestampService::verify()
 * @see Issue #412 - Secure OpenTimestamp verification implementation
 */
uses()->group('unit', 'services', 'opentimestamp', 'verification');

beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency in tests
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

test('verify returns true for valid proof when cli succeeds', function () {
    // Arrange: Valid proof data
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: CLI returns success (exit code 0)
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            // Verify command structure: ots verify --digest <hash>
            return $command === ['ots', 'verify', '--digest', $merkleRoot]
                && is_string($stdin)
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => 'Success! Bitcoin attests data existed as of 2025-12-24 12:00:00 UTC',
            'stderr' => '',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification succeeds
    expect($result, 'CLI verification should return true for valid proof')->toBeTrue();
});

test('verify returns false for invalid proof when cli fails', function () {
    // Arrange: Invalid proof
    $merkleRoot = hash('sha256', 'test-root');
    $invalidProof = 'invalid-proof-data';

    // Mock: CLI returns failure (exit code 1)
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Error: Invalid proof format',
        ]);

    // Act
    $result = $this->service->verify($invalidProof, $merkleRoot);

    // Assert: Verification fails
    expect($result, 'CLI verification should return false for invalid proof')->toBeFalse();
});

test('verify returns false when ots cli not installed', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: ots command not found
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(false);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification fails gracefully
    expect($result, 'Verification should fail when ots CLI is not installed')->toBeFalse();
});

test('verify returns false when cli times out', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: CLI times out
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => -1,
            'stdout' => '',
            'stderr' => 'Process timed out after 10 seconds',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification fails on timeout
    expect($result, 'Verification should fail on CLI timeout')->toBeFalse();
});

test('verify rejects invalid digest format', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Digest must be 64-character hex SHA256 hash');

    $proof = 'valid-proof-data';
    $this->service->verify($proof, 'invalid-digest');
});

test('verify rejects non hex digest', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Digest must be 64-character hex SHA256 hash');

    $proof = 'valid-proof-data';
    $invalidDigest = str_repeat('G', 64); // 'G' is not hex

    $this->service->verify($proof, $invalidDigest);
});

test('verify normalizes uppercase digest to lowercase', function () {
    // Arrange: Uppercase digest
    $merkleRoot = strtoupper(hash('sha256', 'test-root'));
    $proof = buildValidOtsProofForCliVerification();

    // Mock: CLI should receive lowercase digest
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command) use ($merkleRoot) {
            // Verify lowercase normalization
            return $command[3] === strtolower($merkleRoot);
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => 'Success!',
            'stderr' => '',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification succeeds with normalized digest
    expect($result)->toBeTrue();
});

test('verify handles empty proof gracefully', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $emptyProof = '';

    // Mock: CLI should still be called
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Error: Empty proof',
        ]);

    // Act
    $result = $this->service->verify($emptyProof, $merkleRoot);

    // Assert
    expect($result)->toBeFalse();
});

test('verify caches successful verification', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: First call succeeds
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 0,
            'stdout' => 'Success! Bitcoin attests data existed',
            'stderr' => '',
        ]);

    // Act: First verification
    $result1 = $this->service->verify($proof, $merkleRoot);
    expect($result1)->toBeTrue();

    // Act: Second verification should use cache (no CLI call)
    $result2 = $this->service->verify($proof, $merkleRoot);
    expect($result2)->toBeTrue();

    // Assert: Cache was used (no second CLI call expected)
    // Mockery will automatically fail if CLI is called twice
});

test('verify does not cache failed verification', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $invalidProof = 'invalid-proof';

    // Mock: Both calls should fail
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->twice()  // Should be called twice (no caching)
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->twice()  // Should be called twice
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Invalid proof',
        ]);

    // Act: First verification
    $result1 = $this->service->verify($invalidProof, $merkleRoot);
    expect($result1)->toBeFalse();

    // Act: Second verification should NOT use cache (proof may be upgraded later)
    $result2 = $this->service->verify($invalidProof, $merkleRoot);
    expect($result2)->toBeFalse();
});

/**
 * Build a realistic OTS proof structure for testing.
 *
 * This is just sample binary data - actual validation happens in ots CLI.
 */
function buildValidOtsProofForCliVerification(): string
{
    // Minimal OTS proof structure
    $header = "OpenTimestamps proof\x00";
    $opSha256 = "\x00";
    $commitment = random_bytes(32);
    $bitcoinAttestation = "\x05\x88\x96\x0d\x73\xd7\x19\x01\x03";
    $blockHeight = "\xe3\x93\x10";

    return $header.$commitment.$opSha256.$bitcoinAttestation.$blockHeight;
}
