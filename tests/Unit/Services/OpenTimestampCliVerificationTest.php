<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Support\Facades\Cache;

/**
 * Unit tests for OpenTimestamp CLI-based proof verification.
 *
 * Tests the secure implementation using the external Python verifier.
 * Mocks ProcessExecutor to avoid dependency on actual ots-cli installation.
 *
 * @see OpenTimestampService::verify()
 * @see Issue #412 - Secure OpenTimestamp verification implementation
 */
uses()->group('unit', 'services', 'opentimestamp', 'verification');

/**
 * @property ProcessExecutor&Mockery\MockInterface $mockExecutor
 * @property OpenTimestampService $service
 */
beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency in tests
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->mockExecutor->shouldReceive('commandExists')
        ->with('python3')
        ->andReturn(true)
        ->byDefault();
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

test('verify returns true for valid proof when cli succeeds', function () {
    // Arrange: Valid proof data
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: Python script returns success (exit code 0)
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            // Verify command structure: python3 scripts/ots-verify.py <tempfile> <hash>
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === $merkleRoot
                && $stdin === null // No stdin, proof is in tempfile
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => 'SUCCESS: Proof is valid and confirmed on Bitcoin blockchain',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification succeeds
    expect($result, 'Python script verification should return true for valid proof')->toBeTrue();
});

test('verify returns false for invalid proof when cli fails', function () {
    // Arrange: Invalid proof
    $merkleRoot = hash('sha256', 'test-root');
    $invalidProof = 'invalid-proof-data';

    // Mock: Python script returns error (exit code 2 for errors)
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === $merkleRoot
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 2,
            'stdout' => '',
            'stderr' => 'Error: Invalid proof format',
        ]);

    // Act
    $result = $this->service->verify($invalidProof, $merkleRoot);

    // Assert: Verification fails
    expect($result, 'Python script verification should return false for invalid proof')->toBeFalse();
});

test('verify returns false when python not installed', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: python3 not available
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('python3')
        ->once()
        ->andReturn(false);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification fails gracefully
    expect($result, 'Verification should fail when python3 is not installed')->toBeFalse();
});

test('verify returns false when script times out', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForCliVerification();

    // Mock: Python script times out
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === $merkleRoot
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => -1,
            'stdout' => '',
            'stderr' => 'Process timed out after 10 seconds',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Verification fails on timeout
    expect($result, 'Verification should fail on script timeout')->toBeFalse();
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

    // Mock: Python script should receive lowercase digest
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            // Verify lowercase normalization
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === strtolower($merkleRoot)
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => 'SUCCESS: Proof is valid',
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

    // Mock: Python script should still be called
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === $merkleRoot
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 2,
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
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === $merkleRoot
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => 'SUCCESS: Proof is valid and confirmed on Bitcoin blockchain',
        ]);

    // Act: First verification
    $result1 = $this->service->verify($proof, $merkleRoot);
    expect($result1)->toBeTrue()
        ->and(Cache::has("ots:verified:v2:{$merkleRoot}"))->toBeTrue()
        ->and(Cache::has("ots:verified:{$merkleRoot}"))->toBeFalse();

    // Act: Second verification should use cache (no script call)
    $result2 = $this->service->verify($proof, $merkleRoot);
    expect($result2)->toBeTrue();

    // Assert: Cache was used (no second script call expected)
    // Mockery will automatically fail if script is called twice
});

test('verify does not cache failed verification', function () {
    // Arrange
    $merkleRoot = hash('sha256', 'test-root');
    $invalidProof = 'invalid-proof';

    // Mock: Both calls should fail (exit code 1 for invalid proofs)
    $this->mockExecutor
        ->shouldReceive('execute')
        ->twice()  // Should be called twice (no caching of failures)
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot) {
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && str_starts_with($command[2], '/tmp/ots_verify_')
                && $command[3] === $merkleRoot
                && $stdin === null
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'FAILURE: Proof verification failed',
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
