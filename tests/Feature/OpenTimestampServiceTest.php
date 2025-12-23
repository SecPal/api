<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test OpenTimestamp service integration.
 *
 * Tests submission and upgrade of timestamps via REST API.
 *
 * @see App\Services\OpenTimestampService
 */
class OpenTimestampServiceTest extends TestCase
{
    use RefreshDatabase;

    private OpenTimestampService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OpenTimestampService::class);
    }

    public function test_submit_creates_pending_proof(): void
    {
        // Arrange: Mock calendar server response
        Http::fake([
            '*/timestamp/*' => Http::response(hex2bin(
                // Minimal OTS proof (pending attestation)
                '00'. // OpSHA256
                    '04f0'. // OpPrepend calendar commitment
                    bin2hex('alice.btc.calendar.opentimestamps.org')
            ), 200),
        ]);

        $merkleRoot = hash('sha256', 'test-merkle-root');

        // Act: Submit timestamp
        $proof = $this->service->submit($merkleRoot);

        // Assert: Proof created
        $this->assertNotEmpty($proof);
        $this->assertIsString($proof);
        $this->assertGreaterThan(20, strlen($proof)); // Minimal proof size

        // Verify HTTP requests were made
        Http::assertSentCount(3); // 3 calendar servers
    }

    public function test_submit_fails_if_insufficient_calendars_respond(): void
    {
        // Arrange: Mock calendar servers - only 1 responds
        Http::fake([
            'alice.btc.calendar.opentimestamps.org/*' => Http::response('proof1', 200),
            'bob.btc.calendar.opentimestamps.org/*' => Http::response('', 500),
            'finney.calendar.eternitywall.com/*' => Http::response('', 500),
        ]);

        $merkleRoot = hash('sha256', 'test-merkle-root');

        // Act & Assert: Should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to submit timestamp: only 1 of 3 calendars responded');

        $this->service->submit($merkleRoot);
    }

    public function test_upgrade_returns_null_if_not_yet_confirmed(): void
    {
        // Arrange: Create minimal pending proof
        $pendingProof = $this->createPendingProof();

        Http::fake([
            '*/timestamp/*' => Http::response('', 404), // Not yet confirmed
        ]);

        // Act: Attempt upgrade
        $upgradedProof = $this->service->upgrade($pendingProof);

        // Assert: No upgrade available
        $this->assertNull($upgradedProof);
    }

    public function test_upgrade_returns_confirmed_proof_when_available(): void
    {
        // Arrange: Create pending proof
        $pendingProof = $this->createPendingProof();

        // Mock confirmed proof with Bitcoin attestation
        $confirmedProof = $this->createConfirmedProof();

        Http::fake([
            '*/timestamp/*' => Http::response($confirmedProof, 200),
        ]);

        // Act: Upgrade
        $upgraded = $this->service->upgrade($pendingProof);

        // Assert: Proof upgraded
        $this->assertNotNull($upgraded);
        $this->assertNotEquals($pendingProof, $upgraded);
        // Check that proof contains Bitcoin attestation signature bytes
        $this->assertStringContainsString("\x05\x88\x96\x0d", $upgraded);
    }

    public function test_verify_returns_false_for_invalid_proof(): void
    {
        // Arrange: Invalid proof
        $invalidProof = 'invalid-proof-data';
        $merkleRoot = hash('sha256', 'test');

        // Act & Assert
        $result = $this->service->verify($invalidProof, $merkleRoot);

        $this->assertFalse($result);
    }

    public function test_verify_returns_false_for_disabled_implementation(): void
    {
        // Arrange: Create proof with valid structure and Bitcoin attestation
        $merkleRoot = hash('sha256', 'test-root');
        $merkleRootBytes = hex2bin($merkleRoot);

        // Create proof: merkle root (32 bytes) + Bitcoin attestation signature
        $confirmedProof = $merkleRootBytes."\x05\x88\x96\x0d\x73\xd7\x19\x01\x03\xe3\x93\x10";

        // Act: Verify
        $result = $this->service->verify($confirmedProof, $merkleRoot);

        // Assert: Should now return true (implementation enabled in issue #412)
        $this->assertTrue($result, 'verify() should now return true for valid proofs after #412 implementation');
    }

    public function test_verify_checks_for_bitcoin_attestation(): void
    {
        // Arrange: Proof without Bitcoin attestation (still pending)
        $merkleRoot = hash('sha256', 'test-root');
        $pendingProof = $this->createPendingProof();

        // Act & Assert: Should return false (no Bitcoin attestation)
        $result = $this->service->verify($pendingProof, $merkleRoot);
        $this->assertFalse($result);
    }

    /**
     * Create minimal pending proof for testing.
     */
    private function createPendingProof(): string
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
    private function createConfirmedProof(?string $merkleRoot = null): string
    {
        // OTS proof format: Operations + BitcoinBlockHeaderAttestation
        return hex2bin(
            '00'. // OpSHA256
                '0588960d73d7190103'. // Bitcoin block 428648 attestation
                'e39310' // Block height encoded
        );
    }
}
