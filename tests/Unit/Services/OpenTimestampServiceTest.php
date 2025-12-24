<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenTimestampService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for OpenTimestamp proof verification.
 *
 * NOTE: verify() method is currently DISABLED due to security concerns.
 * All tests expect false return value until secure implementation is available.
 *
 * The previous "hybrid approach" implementation was vulnerable to trivial
 * proof forgery attacks (see PR #413 Copilot review, Comment #5).
 *
 * @see App\Services\OpenTimestampService::verify()
 * @see Issue #412 for secure implementation requirements
 */
class OpenTimestampServiceTest extends TestCase
{
    private OpenTimestampService $service;

    protected function setUp(): void
    {
        parent::setUp();
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
        // Arrange: Even with valid proof structure, verify() returns false (disabled)
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $proof = $this->buildValidOtsProof($merkleRootBytes);

        // Act
        $result = $this->service->verify($proof, $merkleRoot);

        // Assert: Always false until secure implementation available
        $this->assertFalse($result, 'verify() should return false (disabled for security)');
    }

    public function test_verify_returns_false_for_empty_proof(): void
    {
        $merkleRoot = hash('sha256', 'test-root');

        $result = $this->service->verify('', $merkleRoot);

        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_malformed_proof(): void
    {
        $merkleRoot = hash('sha256', 'test-root');
        $malformedProof = 'random-binary-data-without-structure';

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

        // Act
        $result = $this->service->verify($proof, $merkleRoot);

        // Assert: Returns false (implementation disabled)
        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_pending_proof(): void
    {
        // Arrange: Pending proof (no Bitcoin attestation yet)
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $pendingProof = $this->buildPendingOtsProof($merkleRootBytes);

        // Act
        $result = $this->service->verify($pendingProof, $merkleRoot);

        // Assert: Returns false (implementation disabled)
        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_any_proof(): void
    {
        // Arrange: Test that verification is consistently disabled for any input
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $proof = $this->buildValidOtsProof($merkleRootBytes);

        // Act: Multiple calls with same proof
        $result1 = $this->service->verify($proof, $merkleRoot);
        $result2 = $this->service->verify($proof, $merkleRoot);

        // Assert: Always false, no caching of "verified" state
        $this->assertFalse($result1);
        $this->assertFalse($result2);
    }

    public function test_verify_handles_malformed_proof_gracefully(): void
    {
        // Arrange: Proof that could throw exception during processing
        $merkleRoot = hash('sha256', 'test-root');
        $malformedProof = 'x'; // Very short

        // Act: Should not throw exception, just return false
        $result = $this->service->verify($malformedProof, $merkleRoot);

        // Assert: Gracefully returns false
        $this->assertFalse($result);
    }

    public function test_verify_handles_uppercase_digest(): void
    {
        // Arrange: Test that uppercase digest doesn't cause issues
        $merkleRoot = strtoupper(hash('sha256', 'test-root'));
        $proof = 'some-proof-data';

        // Act
        $result = $this->service->verify($proof, $merkleRoot);

        // Assert: Returns false (implementation disabled)
        $this->assertFalse($result);
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
