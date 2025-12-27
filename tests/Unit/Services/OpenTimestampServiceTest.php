<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;

/**
 * Unit tests for OpenTimestamp proof verification.
 *
 * Tests CLI-based verification with mocked ProcessExecutor.
 * For detailed CLI verification tests, see OpenTimestampCliVerificationTest.
 *
 * @see App\Services\OpenTimestampService::verify()
 * @see Issue #412 for secure implementation requirements
 */
uses()->group('unit');

beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
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

test('verify returns false when cli not available', function () {
    // Arrange: ots CLI not available
    $merkleRoot = hash('sha256', 'test-root');
    $merkleRootBytes = hex2bin($merkleRoot);
    $proof = buildValidOtsProof($merkleRootBytes);

    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(false);

    // Act
    $result = $this->service->verify($proof, $merkleRoot);

    // Assert: Returns false when CLI not available
    expect($result, 'verify() should return false when ots CLI not available')->toBeFalse();
});

test('verify returns false for empty proof', function () {
    $merkleRoot = hash('sha256', 'test-root');

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

    $result = $this->service->verify('', $merkleRoot);

    expect($result)->toBeFalse();
});

test('verify returns false for malformed proof', function () {
    $merkleRoot = hash('sha256', 'test-root');
    $malformedProof = 'random-binary-data-without-structure';

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

    // Mock: CLI returns success for first call
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once() // Only called once due to caching
        ->andReturn(true);

    $this->mockExecutor
        ->shouldReceive('execute')
        ->once() // Only executed once, second call uses cache
        ->andReturn([
            'exitCode' => 0,
            'stdout' => 'Success!',
            'stderr' => '',
        ]);

    // Act: Multiple calls with same proof
    $result1 = $this->service->verify($proof, $merkleRoot);
    $result2 = $this->service->verify($proof, $merkleRoot);

    // Assert: Both return true
    // Second call returns true from cache (not from CLI execution)
    expect($result1)->toBeTrue();
    expect($result2)->toBeTrue();
});

test('verify handles malformed proof gracefully', function () {
    // Arrange: Proof that could throw exception during processing
    $merkleRoot = hash('sha256', 'test-root');
    $malformedProof = 'x'; // Very short

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

    // Assert: Returns true with normalized digest
    expect($result)->toBeTrue();
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
