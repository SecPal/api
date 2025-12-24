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
 * Tests the verify() method implementation with various proof formats.
 *
 * @see App\Services\OpenTimestampService::verify()
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

    public function test_verify_rejects_empty_proof(): void
    {
        $merkleRoot = hash('sha256', 'test-root');

        $result = $this->service->verify('', $merkleRoot);

        $this->assertFalse($result);
    }

    public function test_verify_rejects_malformed_proof_structure(): void
    {
        $merkleRoot = hash('sha256', 'test-root');
        $malformedProof = 'random-binary-data-without-structure';

        $result = $this->service->verify($malformedProof, $merkleRoot);

        $this->assertFalse($result);
    }

    public function test_verify_accepts_valid_confirmed_proof(): void
    {
        // Arrange: Create valid OTS proof structure
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);

        // Minimal valid OTS proof: header + commitment + operations + Bitcoin attestation
        $proof = $this->buildValidOtsProof($merkleRootBytes);

        // Act
        $result = $this->service->verify($proof, $merkleRoot);

        // Assert
        $this->assertTrue($result);
    }

    public function test_verify_rejects_proof_with_wrong_commitment(): void
    {
        // Arrange: Proof for different merkle root
        $merkleRoot = hash('sha256', 'test-root');
        $differentRoot = hash('sha256', 'different-root');
        $differentRootBytes = hex2bin($differentRoot);

        $proof = $this->buildValidOtsProof($differentRootBytes);

        // Act
        $result = $this->service->verify($proof, $merkleRoot);

        // Assert: Should fail because commitment doesn't match
        $this->assertFalse($result);
    }

    public function test_verify_rejects_pending_proof_without_bitcoin_attestation(): void
    {
        // Arrange: Pending proof (no Bitcoin attestation yet)
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);

        $pendingProof = $this->buildPendingOtsProof($merkleRootBytes);

        // Act
        $result = $this->service->verify($pendingProof, $merkleRoot);

        // Assert: Should fail because no Bitcoin attestation
        $this->assertFalse($result);
    }

    public function test_verify_uses_cache_for_verified_proofs(): void
    {
        // Arrange
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);
        $proof = $this->buildValidOtsProof($merkleRootBytes);

        // Set up cache spy before any verification calls
        Cache::spy();

        // Act: First verification (should verify and cache)
        $result1 = $this->service->verify($proof, $merkleRoot);

        // Act: Second verification (should hit cache)
        $result2 = $this->service->verify($proof, $merkleRoot);

        // Assert: Both return true
        $this->assertTrue($result1);
        $this->assertTrue($result2);

        // Verify cache was used for both calls
        Cache::shouldHaveReceived('remember')->twice();
    }

    public function test_verify_handles_malformed_proof_gracefully(): void
    {
        // Arrange: Proof that will throw exception during processing
        $merkleRoot = hash('sha256', 'test-root');
        $malformedProof = 'x'; // Too short, will fail early

        // Act
        $result = $this->service->verify($malformedProof, $merkleRoot);

        // Assert: Should gracefully return false
        $this->assertFalse($result);
    }

    public function test_verify_handles_proof_without_header(): void
    {
        // Arrange: Proof without OpenTimestamp header but with valid structure
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);

        // Legacy format: just commitment + attestation (no header)
        $legacyProof = $merkleRootBytes."\x05\x88\x96\x0d\x73\xd7\x19\x01\x03";

        // Act
        $result = $this->service->verify($legacyProof, $merkleRoot);

        // Assert: Should still verify successfully
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
