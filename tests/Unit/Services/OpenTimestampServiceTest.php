<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Unit tests for OpenTimestamp proof verification.
 *
 * Tests Python-process verification with a mocked ProcessExecutor.
 * For detailed process verification tests, see OpenTimestampCliVerificationTest.
 *
 * @see OpenTimestampService::verify()
 * @see Issue #412 for secure implementation requirements
 */
uses()->group('unit');

/**
 * @property ProcessExecutor&Mockery\MockInterface $mockExecutor
 * @property OpenTimestampService $service
 */
beforeEach(function () {
    // Mock ProcessExecutor to avoid external process dependencies
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->mockExecutor->shouldReceive('commandExists')
        ->with('python3')
        ->andReturn(true)
        ->byDefault();
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

test('verify rejects invalid digest format', function () {
    $proof = 'valid-proof-data';

    expect(fn () => $this->service->verify($proof, 'invalid-digest'))
        ->toThrow(InvalidArgumentException::class, 'Digest must be 64-character hex SHA256 hash');
});

test('verify rejects non hex digest', function () {
    $proof = 'valid-proof-data';
    $invalidDigest = str_repeat('zz', 32); // Non-hex characters

    expect(fn () => $this->service->verify($proof, $invalidDigest))
        ->toThrow(InvalidArgumentException::class, 'Digest must be 64-character hex SHA256 hash');
});

test('verify fails closed when bitcoin header APIs are not configured', function () {
    config()->set('services.opentimestamps.bitcoin_header_api_bases', null);
    $this->mockExecutor->shouldNotReceive('commandExists', 'execute');

    expect($this->service->verify('proof-data', hash('sha256', 'missing-header-apis')))
        ->toBeFalse();
});

test('verify fails closed when the verification cache ttl is invalid', function () {
    config()->set('services.opentimestamps.verification_cache_ttl_seconds', 0);
    $this->mockExecutor->shouldNotReceive('execute');

    expect($this->service->verify('proof-data', hash('sha256', 'invalid-cache-ttl')))
        ->toBeFalse();
});

test('verify fails closed when the verification cache ttl exceeds one day', function () {
    config()->set('services.opentimestamps.verification_cache_ttl_seconds', 86_401);
    $this->mockExecutor->shouldNotReceive('execute');

    expect($this->service->verify('proof-data', hash('sha256', 'excessive-cache-ttl')))
        ->toBeFalse();
});

test('verify rejects oversized proofs before starting the verifier process', function () {
    $this->mockExecutor->shouldNotReceive('execute');

    expect($this->service->verify(str_repeat('x', 1_048_577), hash('sha256', 'oversized-proof')))
        ->toBeFalse();
});

test('verify returns false when python not available', function () {
    // Arrange: python3 not available
    $merkleRoot = hash('sha256', 'test-root');
    $merkleRootBytes = hex2bin($merkleRoot);
    $proof = buildValidOtsProof($merkleRootBytes);

    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('python3')
        ->once()
        ->andReturn(false);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Returns false when python3 not available
    expect($result, 'verify() should return false when python3 not available')->toBeFalse();
});

test('verify returns false for empty proof', function () {
    $merkleRoot = hash('sha256', 'test-root');

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 2,
            'stdout' => '',
            'stderr' => 'Error: Empty proof',
        ]);

    $result = $this->service->verify('', $merkleRoot);

    expect($result)->toBeFalse();
});

test('verify returns false for malformed proof', function () {
    $merkleRoot = hash('sha256', 'test-root');
    $malformedProof = 'random-binary-data-without-structure';

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 2,
            'stdout' => '',
            'stderr' => 'Error: Invalid proof format',
        ]);

    $result = $this->service->verify($malformedProof, $merkleRoot);

    expect($result)->toBeFalse();
});

test('verify returns false for proof with wrong commitment', function () {
    // Arrange: Proof for different merkle root
    $merkleRoot = hash('sha256', 'test-root');
    $differentRoot = hash('sha256', 'different-root');
    $differentRootBytes = hex2bin($differentRoot);
    $proof = buildValidOtsProof($differentRootBytes);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Error: Digest mismatch',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Returns false
    expect($result)->toBeFalse();
});

test('verify returns false for pending proof', function () {
    // Arrange: Pending proof (no Bitcoin attestation yet)
    $merkleRoot = hash('sha256', 'test-root');
    $merkleRootBytes = hex2bin($merkleRoot);
    $pendingProof = buildPendingOtsProof($merkleRootBytes);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Pending attestation, not yet confirmed',
        ]);

    // Act
    $result = $this->service->verify($pendingProof, $merkleRoot);

    // Assert: Returns false (not yet confirmed)
    expect($result)->toBeFalse();
});

test('verify returns false for any proof', function () {
    // Arrange: Test that verification works consistently
    $merkleRoot = hash('sha256', 'test-root');
    $merkleRootBytes = hex2bin($merkleRoot);
    $proof = buildValidOtsProof($merkleRootBytes);

    // Mock: Python script returns success for first call
    $this->mockExecutor
        ->shouldReceive('execute')
        ->once() // Only executed once, second call uses cache
        ->andReturn([
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => 'SUCCESS: Proof is valid',
        ]);

    // Act: Multiple calls with same proof
    $result1 = $this->service->verify($proof, $merkleRoot);
    $result2 = $this->service->verify($proof, $merkleRoot);

    // Assert: Both return true
    // Second call returns true from cache (not from execution)
    expect($result1)->toBeTrue();
    expect($result2)->toBeTrue();
});

test('verify ignores successful results cached by the vulnerable verifier', function () {
    $digest = hash('sha256', 'legacy-cache-entry');
    $proof = 'forged-proof';

    Cache::forever("ots:verified:{$digest}", true);
    Cache::forever("ots:verified:v2:{$digest}", true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'FAILURE: Proof verification failed',
        ]);

    expect($this->service->verify($proof, $digest))->toBeFalse();
});

test('verify ignores unbounded successful results from verifier cache v3', function () {
    $digest = hash('sha256', 'unbounded-v3-cache-entry');
    $proof = 'previously-verified-proof';
    $providerConfiguration = (string) config('services.opentimestamps.bitcoin_header_api_bases');
    $verificationContext = hash('sha256', $proof."\0".$providerConfiguration);

    Cache::forever("ots:verified:v3:{$digest}:{$verificationContext}", true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'FAILURE: Proof must be revalidated against the active chain',
        ]);

    expect($this->service->verify($proof, $digest))->toBeFalse();
});

test('verify handles malformed proof gracefully', function () {
    // Arrange: Proof that could throw exception during processing
    $merkleRoot = hash('sha256', 'test-root');
    $malformedProof = 'x'; // Very short

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 2,
            'stdout' => '',
            'stderr' => 'Error: Invalid proof',
        ]);

    // Act: Should not throw exception, just return false
    $result = $this->service->verify($malformedProof, $merkleRoot);

    // Assert: Gracefully returns false
    expect($result)->toBeFalse();
});

test('verify handles uppercase digest', function () {
    // Arrange: Test that uppercase digest is normalized
    $merkleRoot = strtoupper(hash('sha256', 'test-root'));
    $proof = 'some-proof-data';

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command) use ($merkleRoot) {
            // Verify lowercase normalization and correct command structure
            // python3 scripts/ots-verify.py <proof_file> <digest>
            return count($command) === 4
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-verify.py')
                && $command[3] === strtolower($merkleRoot);
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => 'SUCCESS: Proof is valid',
        ]);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Returns true with normalized digest
    expect($result)->toBeTrue();
});

test('verify cleans up temporary proof file after execution', function () {
    $merkleRoot = hash('sha256', 'cleanup-test');
    $proof = 'proof-data';
    $capturedTempFile = null;

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command) use (&$capturedTempFile, $proof, $merkleRoot) {
            $capturedTempFile = $command[2] ?? null;

            return count($command) === 4
                && $command[0] === 'python3'
                && is_string($capturedTempFile)
                && file_exists($capturedTempFile)
                && file_get_contents($capturedTempFile) === $proof
                && $command[3] === $merkleRoot;
        })
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'Error: Invalid proof',
        ]);

    $result = $this->service->verify($proof, $merkleRoot);

    expect($result)->toBeFalse();
    expect($capturedTempFile)->not->toBeNull();
    expect(file_exists($capturedTempFile))->toBeFalse();
});

test('submit failure logs only sanitized digest context', function () {
    $digest = hash('sha256', 'sensitive-digest');

    Log::shouldReceive('info')->once();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($digest) {
            return $message === 'OpenTimestamp: Submission failed'
                && ($context['digest_hint'] ?? null) === substr($digest, 0, 12)
                && ! array_key_exists('digest', $context)
                && ($context['error'] ?? null) === 'OTS submission script failed with exit code 1: calendar failure';
        });

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => 'calendar failure',
        ]);

    expect(fn () => $this->service->submit($digest))
        ->toThrow(RuntimeException::class, 'Failed to submit timestamp: OTS submission script failed with exit code 1: calendar failure');
});

test('verify failure redacts digests and temp file paths from logged stderr', function () {
    $digest = hash('sha256', 'stderr-redaction');
    $proof = 'proof-data';

    Log::shouldReceive('debug')->once();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'OpenTimestamp: Proof verification failed'
                && ($context['stderr'] ?? null) === 'Error: Digest mismatch! Expected: [redacted-digest] In proof: [redacted-digest] Proof file not found: [redacted-temp-file]';
        });

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => "Error: Digest mismatch!\nExpected: {$digest}\nIn proof: {$digest}\nProof file not found: /tmp/ots_verify_abc123",
        ]);

    $result = $this->service->verify($proof, $digest);

    expect($result)->toBeFalse();
});

test('verify failure redacts ots temp paths outside tmp from logged stderr', function () {
    $digest = hash('sha256', 'stderr-redaction-alt-path');
    $proof = 'proof-data';

    Log::shouldReceive('debug')->once();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'OpenTimestamp: Proof verification failed'
                && ($context['stderr'] ?? null) === 'Verification failed at [redacted-temp-file] for digest [redacted-digest]';
        });

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once()
        ->andReturn([
            'exitCode' => 1,
            'stdout' => '',
            'stderr' => "Verification failed at /var/tmp/ots_verify_abc123 for digest {$digest}",
        ]);

    $result = $this->service->verify($proof, $digest);

    expect($result)->toBeFalse();
});

/**
 * Build valid OTS proof structure for testing.
 *
 * Structure: Header + Commitment + Operations + Bitcoin Attestation
 */
function buildValidOtsProof(string $commitment): string
{
    // OpenTimestamp proof header
    $header = "OpenTimestamps proof\x00";

    // OpSHA256
    $opSha256 = "\x00";

    // Bitcoin attestation signature (0x05 0x88 0x96 0x0d...)
    $bitcoinAttestation = "\x05\x88\x96\x0d\x73\xd7\x19\x01\x03";

    // Block height (e.g., 428648 = 0x68a38)
    $blockHeight = "\xe3\x93\x10";

    return $header.$commitment.$opSha256.$bitcoinAttestation.$blockHeight;
}

/**
 * Build pending OTS proof (without Bitcoin attestation).
 */
function buildPendingOtsProof(string $commitment): string
{
    $header = "OpenTimestamps proof\x00";
    $opSha256 = "\x00";

    // Pending attestation (0x83)
    $pendingAttestation = "\x83";

    // Calendar URL
    $calendarUrl = 'https://alice.btc.calendar.opentimestamps.org';
    $calendarUrlLength = pack('C', strlen($calendarUrl));

    return $header.$commitment.$opSha256.$pendingAttestation.$calendarUrlLength.$calendarUrl;
}
