<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for OpenTimestamp proof verification.
 *
 * Tests CLI-based verification with mocked ProcessExecutor.
 * For detailed CLI verification tests, see OpenTimestampCliVerificationTest.
 *
 * @see App\Services\OpenTimestampService::verify()
 * @see Issue #412 for secure implementation requirements
 */
class OpenTimestampServiceTest extends TestCase
{
    private OpenTimestampService $service;

    /** @var Mockery\MockInterface&ProcessExecutor */
    private $mockExecutor;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock ProcessExecutor to avoid CLI dependency
        $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
        $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

        $this->service = app(OpenTimestampService::class);
    }

    public function test_verify_rejects_invalid_digest_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Digest must be 64-character hex SHA256 hash');

        $proof = 'valid-proof-data';
        $this->service->verify($proof, 'invalid-digest');
    }

    public function test_verify_rejects_non_hex_digest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Digest must be 64-character hex SHA256 hash');

        $proof = 'valid-proof-data';
        $invalidDigest = str_repeat('zz', 32); // Non-hex characters
        $this->service->verify($proof, $invalidDigest);
    }

    public function test_verify_returns_false_for_disabled_implementation(): void
    {
        // Arrange: ots CLI not available
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $proof = $this->buildValidOtsProof($merkleRootBytes);

        $this->mockExecutor
            ->shouldReceive('commandExists')
            ->with('ots')
            ->once()
            ->andReturn(false);

        // Act
        $result = $this->service->verify($proof, $merkleRoot);

        // Assert: Returns false when CLI not available
        $this->assertFalse($result, 'verify() should return false when ots CLI not available');
    }

    public function test_verify_returns_false_for_empty_proof(): void
    {
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

        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_malformed_proof(): void
    {
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

        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_proof_with_wrong_commitment(): void
    {
        // Arrange: Proof for different merkle root
        $merkleRoot = hash('sha256', 'test-root');
        $differentRoot = hash('sha256', 'different-root');
        $differentRootBytes = hex2bin($differentRoot);
        $proof = $this->buildValidOtsProof($differentRootBytes);

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
        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_pending_proof(): void
    {
        // Arrange: Pending proof (no Bitcoin attestation yet)
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $pendingProof = $this->buildPendingOtsProof($merkleRootBytes);

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
        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_any_proof(): void
    {
        // Arrange: Test that verification works consistently
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $proof = $this->buildValidOtsProof($merkleRootBytes);

        // Mock: CLI returns success both times
        $this->mockExecutor
            ->shouldReceive('commandExists')
            ->with('ots')
            ->twice()
            ->andReturn(true);

        $this->mockExecutor
            ->shouldReceive('execute')
            ->twice()
            ->andReturn([
                'exitCode' => 0,
                'stdout' => 'Success!',
                'stderr' => '',
            ]);

        // Act: Multiple calls with same proof
        $result1 = $this->service->verify($proof, $merkleRoot);
        $result2 = $this->service->verify($proof, $merkleRoot);

        // Assert: Both return true (no state issues)
        $this->assertTrue($result1);
        $this->assertTrue($result2);
    }

    public function test_verify_handles_malformed_proof_gracefully(): void
    {
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
        $this->assertFalse($result);
    }

    public function test_verify_handles_uppercase_digest(): void
    {
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
        $this->assertTrue($result);
    }

    /**
     * Build valid OTS proof structure for testing.
     *
     * Structure: Header + Commitment + Operations + Bitcoin Attestation
     */
    private function buildValidOtsProof(string $commitment): string
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
    private function buildPendingOtsProof(string $commitment): string
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
}
