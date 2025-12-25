<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Unit tests for OpenTimestamp proof selection from multiple calendars.
 *
 * Tests that the service correctly selects a valid proof when multiple calendar
 * servers respond. True OTS proof merging (combining attestations with fork operations)
 * is not implemented - see Issue #410 (Full OTS Parser) and Issue #411 (Proof Merging).
 *
 * Current behavior: Returns the first valid calendar proof, ensuring we always
 * store a structurally valid OTS proof that can be verified by external tools.
 *
 * @see App\Services\OpenTimestampService::mergeProofs()
 * @see Issue #411: Implement proper OpenTimestamp proof merging
 */
uses()->group('unit');

beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

/**
 * Test that submit() handles multiple calendar responses.
 *
 * When 3 calendars respond, the service should return a valid proof.
 * Current implementation returns the first calendar's proof.
 */
test('submit handles multiple calendar responses', function () {
    // Arrange: Mock 3 different calendar responses
    $digest = hash('sha256', 'test-merkle-root');
    $digestBytes = hex2bin($digest);

    // Each calendar returns a different pending proof with its own attestation
    $aliceProof = buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
    $bobProof = buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');
    $finneyProof = buildCalendarProof($digestBytes, 'https://finney.calendar.eternitywall.com');

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($aliceProof, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($bobProof, 200),
        'finney.calendar.eternitywall.com/*' => Http::response($finneyProof, 200),
    ]);

    // Act: Submit timestamp
    $result = $this->service->submit($digest);

    // Assert: Should return first calendar's proof (alice)
    // Note: This test validates current behavior (first proof selection)
    // not ideal merged proof behavior (which requires Issue #410)
    expect($result)->toContain('alice.btc.calendar.opentimestamps.org',
        'Should return first calendar proof (alice)');
});

/**
 * Test that proof selection returns a valid-sized proof.
 *
 * The returned proof should be at least as large as a single calendar proof.
 */
test('selected proof has valid size', function () {
    // Arrange: Mock 2 calendar responses
    $digest = hash('sha256', 'test-data');
    $digestBytes = hex2bin($digest);

    $proof1 = buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
    $proof2 = buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');
    $proof3 = buildCalendarProof($digestBytes, 'https://finney.calendar.eternitywall.com');

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($proof1, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($proof2, 200),
        'finney.calendar.eternitywall.com/*' => Http::response($proof3, 200),
    ]);

    // Act
    $result = $this->service->submit($digest);

    // Assert: Result should be at least as large as a single proof
    expect(strlen($result))->toBeGreaterThanOrEqual(
        strlen($proof1),
        'Selected proof should be at least as large as individual proof'
    );
});

/**
 * Test that selected proof preserves the commitment (digest).
 *
 * The selected proof should still contain the original commitment,
 * allowing verification to work correctly.
 */
test('selected proof preserves commitment', function () {
    // Arrange: Mock 2 calendar responses
    $digest = hash('sha256', 'test-commitment');
    $digestBytes = hex2bin($digest);

    $proof1 = buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
    $proof2 = buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($proof1, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($proof2, 200),
        'finney.calendar.eternitywall.com/*' => Http::response($proof2, 200),
    ]);

    // Act
    $result = $this->service->submit($digest);

    // Assert: Result should contain the original digest bytes
    expect($result)->toContain(
        $digestBytes,
        'Selected proof should preserve the original commitment (digest)'
    );
});

/**
 * Test that minimum calendar responses are enforced.
 *
 * When only 2 calendars respond (meeting the minimum threshold),
 * the first valid proof should be returned.
 */
test('handles minimum calendar responses', function () {
    // Arrange: Mock only 2 calendars responding (minimum threshold)
    $digest = hash('sha256', 'test-single');
    $digestBytes = hex2bin($digest);

    $aliceProof = buildCalendarProof($digestBytes, 'https://alice.btc.calendar.opentimestamps.org');
    $bobProof = buildCalendarProof($digestBytes, 'https://bob.btc.calendar.opentimestamps.org');

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($aliceProof, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($bobProof, 200),
        'finney.calendar.eternitywall.com/*' => Http::response('', 500), // Failed
    ]);

    // Act
    $result = $this->service->submit($digest);

    // Assert: Should return first responding calendar's proof
    expect($result)->toContain('alice.btc.calendar.opentimestamps.org');
});

/**
 * Build a simplified calendar proof with pending attestation for testing.
 *
 * Note: This creates a simplified proof structure for testing purposes.
 * Real OTS calendar responses have more complex binary structures with
 * proper VarInt encoding and operation trees.
 *
 * Structure: Digest + OpSHA256 + PendingAttestation + Calendar URL
 *
 * @param  string  $digest  Binary digest (32 bytes)
 * @param  string  $calendarUrl  Calendar server URL
 * @return string Binary OTS-like proof (simplified for testing)
 */
function buildCalendarProof(string $digest, string $calendarUrl): string
{
    // Simplified OTS proof structure for testing:
    // - SHA256 operation (0x00)
    // - Pending attestation (0x83)
    // - Calendar URL length (VarInt encoded, simplified to single byte)
    // - Calendar URL (UTF-8)

    $opSha256 = "\x00";
    $pendingAttestation = "\x83"; // Pending attestation opcode

    // VarInt encoding (simplified for URLs < 255 bytes)
    $urlLength = pack('C', strlen($calendarUrl));

    return $digest.$opSha256.$pendingAttestation.$urlLength.$calendarUrl;
}
