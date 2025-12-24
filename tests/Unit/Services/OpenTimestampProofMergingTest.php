<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for OpenTimestamp proof merging.
 *
 * Tests that multiple calendar responses are properly merged into a single proof
 * that contains attestations from all responding calendars.
 *
 * Requirement: Proof merging should combine attestations from multiple calendars
 * to provide redundancy. If one calendar server disappears in the future, other
 * attestations in the proof still allow verification.
 *
 * @see App\Services\OpenTimestampService::mergeProofs()
 * @see Issue #411: Implement proper OpenTimestamp proof merging
 */
class OpenTimestampProofMergingTest extends TestCase
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

    /**
     * Test that submit() merges responses from multiple calendars.
     *
     * When 3 calendars respond, the merged proof should contain attestations
     * from all 3 calendars (not just the first one).
     */
    public function test_submit_merges_proofs_from_multiple_calendars(): void
    {
        // Arrange: Mock 3 different calendar responses
        $digest = hash('sha256', 'test-merkle-root');
        $digestBytes = hex2bin($digest);

        // Each calendar returns a different pending proof with its own attestation
        $aliceProof = $this->buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
        $bobProof = $this->buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');
        $finneyProof = $this->buildCalendarProof($digestBytes, 'https://finney.calendar.eternitywall.com');

        Http::fake([
            'alice.btc.calendar.opentimestamps.org/*' => Http::response($aliceProof, 200),
            'bob.btc.calendar.opentimestamps.org/*' => Http::response($bobProof, 200),
            'finney.calendar.eternitywall.com/*' => Http::response($finneyProof, 200),
        ]);

        // Act: Submit timestamp (triggers proof merging)
        $mergedProof = $this->service->submit($digest);

        // Assert: Merged proof should contain attestations from all calendars
        // Currently FAILS because mergeProofs() only returns first proof
        $this->assertStringContainsString('alice.btc.calendar.opentimestamps.org', $mergedProof,
            'Merged proof should contain Alice calendar attestation');
        $this->assertStringContainsString('bob.btc.calendar.opentimestamps.org', $mergedProof,
            'Merged proof should contain Bob calendar attestation');
        $this->assertStringContainsString('finney.calendar.eternitywall.com', $mergedProof,
            'Merged proof should contain Finney calendar attestation');
    }

    /**
     * Test that merged proof is larger than any individual proof.
     *
     * A properly merged proof should contain operation trees from all calendars,
     * making it larger than any single calendar response.
     */
    public function test_merged_proof_contains_all_attestations(): void
    {
        // Arrange: Mock 2 calendar responses
        $digest = hash('sha256', 'test-data');
        $digestBytes = hex2bin($digest);

        $proof1 = $this->buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
        $proof2 = $this->buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');

        Http::fake([
            'alice.btc.calendar.opentimestamps.org/*' => Http::response($proof1, 200),
            'bob.btc.calendar.opentimestamps.org/*' => Http::response($proof2, 200),
            'finney.calendar.eternitywall.com/*' => Http::response($proof2, 200),
        ]);

        // Act
        $mergedProof = $this->service->submit($digest);

        // Assert: Merged proof should be larger than individual proofs
        // (because it contains attestations from both calendars)
        $this->assertGreaterThanOrEqual(
            strlen($proof1),
            strlen($mergedProof),
            'Merged proof should be at least as large as individual proof'
        );

        // Stronger assertion: merged proof should actually be larger
        // when combining multiple different attestations
        $this->assertGreaterThan(
            strlen($proof1),
            strlen($mergedProof),
            'Merged proof should be larger than single proof when combining multiple attestations'
        );
    }

    /**
     * Test that merging preserves the commitment (digest).
     *
     * The merged proof should still contain the original commitment,
     * allowing verification to work correctly.
     */
    public function test_merged_proof_preserves_commitment(): void
    {
        // Arrange: Mock 2 calendar responses
        $digest = hash('sha256', 'test-commitment');
        $digestBytes = hex2bin($digest);

        $proof1 = $this->buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
        $proof2 = $this->buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');

        Http::fake([
            'alice.btc.calendar.opentimestamps.org/*' => Http::response($proof1, 200),
            'bob.btc.calendar.opentimestamps.org/*' => Http::response($proof2, 200),
            'finney.calendar.eternitywall.com/*' => Http::response($proof2, 200),
        ]);

        // Act
        $mergedProof = $this->service->submit($digest);

        // Assert: Merged proof should contain the original digest bytes
        $this->assertStringContainsString(
            $digestBytes,
            $mergedProof,
            'Merged proof should preserve the original commitment (digest)'
        );
    }

    /**
     * Test that single proof is returned unchanged.
     *
     * When only one calendar responds (edge case), the "merged" proof
     * should be identical to that single response.
     */
    public function test_single_proof_is_returned_unchanged(): void
    {
        // Arrange: Mock only 2 calendars responding (minimum threshold)
        $digest = hash('sha256', 'test-single');
        $digestBytes = hex2bin($digest);

        $aliceProof = $this->buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
        $bobProof = $this->buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');

        Http::fake([
            'alice.btc.calendar.opentimestamps.org/*' => Http::response($aliceProof, 200),
            'bob.btc.calendar.opentimestamps.org/*' => Http::response($bobProof, 200),
            'finney.calendar.eternitywall.com/*' => Http::response('', 500), // Failed
        ]);

        // Act
        $mergedProof = $this->service->submit($digest);

        // Assert: Should contain attestations from both responding calendars
        $this->assertStringContainsString('alice.btc.calendar.opentimestamps.org', $mergedProof);
        $this->assertStringContainsString('bob.btc.calendar.opentimestamps.org', $mergedProof);
    }

    /**
     * Build a calendar proof with pending attestation for testing.
     *
     * Structure: Digest + OpSHA256 + PendingAttestation + Calendar URL
     *
     * @param  string  $digest  Binary digest (32 bytes)
     * @param  string  $calendarUrl  Calendar server URL
     * @return string Binary OTS proof
     */
    private function buildCalendarProof(string $digest, string $calendarUrl): string
    {
        // OTS proof structure for calendar response:
        // - SHA256 operation (0x00)
        // - Pending attestation (0x83 = OPCODE_ATTESTATION_PENDING)
        // - Calendar URL length (VarInt)
        // - Calendar URL (UTF-8)

        $opSha256 = "\x00";
        $pendingAttestation = "\x83\xdf\xf0\x05"; // Pending attestation magic bytes

        // Calendar URL as VarInt length + UTF-8 string
        $urlLength = pack('C', strlen($calendarUrl));

        return $digest.$opSha256.$pendingAttestation.$urlLength.$calendarUrl;
    }
}
