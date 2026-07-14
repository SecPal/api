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
 * Unit tests for OpenTimestamp Python-process proof verification.
 *
 * Tests the secure implementation using the external Python verifier.
 * Mocks ProcessExecutor to avoid dependency on the actual Python runtime.
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
    // Mock ProcessExecutor to avoid Python-process dependencies in tests
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->mockExecutor->shouldReceive('commandExists')
        ->with('python3')
        ->andReturn(true)
        ->byDefault();
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

test('verify passes config cached bitcoin header APIs to the python process', function () {
    $merkleRoot = hash('sha256', 'configured-header-apis');
    $proof = buildValidOtsProofForProcessVerification();
    $configuredApiBases = 'https://bitcoin-one.test/api,https://bitcoin-two.test/api';
    config()->set('services.opentimestamps.bitcoin_header_api_bases', $configuredApiBases);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout, $environment) use ($configuredApiBases) {
            return ($environment['OTS_BITCOIN_HEADER_API_BASES'] ?? null) === $configuredApiBases;
        })
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'FAILURE: Proof verification failed',
        ]);

    expect($this->service->verify($proof, $merkleRoot))->toBeFalse();
});

test('verify returns true for valid proof when python verifier succeeds', function () {
    // Arrange: Valid proof data
    $merkleRoot = hash('sha256', 'test-root');
    $proof = buildValidOtsProofForProcessVerification();

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

test('verify returns false for invalid proof when python verifier fails', function () {
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
    $proof = buildValidOtsProofForProcessVerification();

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
    $proof = buildValidOtsProofForProcessVerification();

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
    $proof = buildValidOtsProofForProcessVerification();

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
    $proof = buildValidOtsProofForProcessVerification();

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
    $apiBases = config('services.opentimestamps.bitcoin_header_api_bases');
    $cacheKey = "ots:verified:v3:{$merkleRoot}:".hash('sha256', $proof."\0".$apiBases);
    expect($result1)->toBeTrue()
        ->and(Cache::has($cacheKey))->toBeTrue();

    // Act: Second verification should use cache (no script call)
    $result2 = $this->service->verify($proof, $merkleRoot);
    expect($result2)->toBeTrue();

    // Assert: Cache was used (no second script call expected)
    // Mockery will automatically fail if script is called twice
});

test('verify does not reuse a successful cache entry for another proof or provider configuration', function () {
    $merkleRoot = hash('sha256', 'cache-context');
    $firstProof = 'first-proof';
    $secondProof = 'second-proof';

    $this->mockExecutor
        ->shouldReceive('execute')
        ->times(3)
        ->andReturn(
            ['exitCode' => 0, 'stdout' => '', 'stderr' => 'SUCCESS'],
            ['exitCode' => 1, 'stdout' => '', 'stderr' => 'FAILURE'],
            ['exitCode' => 1, 'stdout' => '', 'stderr' => 'FAILURE'],
        );

    expect($this->service->verify($firstProof, $merkleRoot))->toBeTrue()
        ->and($this->service->verify($secondProof, $merkleRoot))->toBeFalse();

    config()->set(
        'services.opentimestamps.bitcoin_header_api_bases',
        'https://replacement-one.test/api,https://replacement-two.test/api',
    );

    expect($this->service->verify($firstProof, $merkleRoot))->toBeFalse()
        ->and(Cache::has("ots:verified:v2:{$merkleRoot}"))->toBeFalse()
        ->and(Cache::has("ots:verified:{$merkleRoot}"))->toBeFalse();
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
 * This is just sample binary data - actual validation happens in the Python verifier.
 */
function buildValidOtsProofForProcessVerification(): string
{
    // Minimal OTS proof structure
    $header = "OpenTimestamps proof\x00";
    $opSha256 = "\x00";
    $commitment = random_bytes(32);
    $bitcoinAttestation = "\x05\x88\x96\x0d\x73\xd7\x19\x01\x03";
    $blockHeight = "\xe3\x93\x10";

    return $header.$commitment.$opSha256.$bitcoinAttestation.$blockHeight;
}
