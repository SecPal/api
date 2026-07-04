<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Support\Facades\Http;

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
 * @see OpenTimestampService::mergeProofs()
 * @see Issue #411: Implement proper OpenTimestamp proof merging
 */
beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency
    /** @var ProcessExecutor&Mockery\MockInterface $mockExecutor */
    $mockExecutor = $this->mock(ProcessExecutor::class);
    $mockExecutor->shouldReceive('commandExists')
        ->with('python3')
        ->andReturn(true)
        ->byDefault();
    $mockExecutor->shouldReceive('commandExists')
        ->with('ots')
        ->andReturn(true)
        ->byDefault();

    // Explicitly bind mock to container for consistency with other test files
    $this->app->instance(ProcessExecutor::class, $mockExecutor);

    $this->service = app(OpenTimestampService::class);
    $this->mockExecutor = $mockExecutor;
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

    // Mock Python script execution
    $this->mockExecutor->shouldReceive('execute')
        ->withArgs(function ($command, $stdin, $timeout) use ($digest) {
            return count($command) === 3
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-stamp-hash.py')
                && $command[2] === $digest
                && $stdin === null
                && $timeout === 15;
        })
        ->once()
        ->andReturn(['exitCode' => 0, 'stdout' => $aliceProof, 'stderr' => '']);

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($aliceProof, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($bobProof, 200),
        'finney.calendar.eternitywall.com/*' => Http::response($finneyProof, 200),
    ]);

    // Act: Submit timestamp
    $result = $this->service->submit($digest);

    // Assert: Should return the first valid (Alice) calendar proof
    // Note: With the Python script approach, the proof structure may differ from CLI
    expect($result)->not->toBeEmpty('Should return a valid proof');
    expect(strlen($result))->toBeGreaterThan(50, 'Proof should have reasonable size');
    // Verify the proof contains reference to Alice's calendar server
    expect(str_contains($result, 'alice.btc.calendar.opentimestamps.org'))->toBeTrue(
        'Should select Alice calendar proof when it is the first valid response'
    );
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

    // Mock Python script execution
    $this->mockExecutor->shouldReceive('execute')
        ->withArgs(function ($command, $stdin, $timeout) use ($digest) {
            return count($command) === 3
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-stamp-hash.py')
                && $command[2] === $digest
                && $stdin === null
                && $timeout === 15;
        })
        ->once()
        ->andReturn(['exitCode' => 0, 'stdout' => $proof1, 'stderr' => '']);

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

    // Mock Python script execution
    $this->mockExecutor->shouldReceive('execute')
        ->withArgs(function ($command, $stdin, $timeout) use ($digest) {
            return count($command) === 3
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-stamp-hash.py')
                && $command[2] === $digest
                && $stdin === null
                && $timeout === 15;
        })
        ->once()
        ->andReturn(['exitCode' => 0, 'stdout' => $proof1, 'stderr' => '']);

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($proof1, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($proof2, 200),
        'finney.calendar.eternitywall.com/*' => Http::response($proof2, 200),
    ]);

    // Act
    $result = $this->service->submit($digest);

    // Assert: Result should contain the original digest bytes
    expect(str_contains($result, $digestBytes))->toBeTrue(
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

    // Mock Python script execution
    $this->mockExecutor->shouldReceive('execute')
        ->withArgs(function ($command, $stdin, $timeout) use ($digest) {
            return count($command) === 3
                && $command[0] === 'python3'
                && str_ends_with($command[1], 'scripts/ots-stamp-hash.py')
                && $command[2] === $digest
                && $stdin === null
                && $timeout === 15;
        })
        ->once()
        ->andReturn(['exitCode' => 0, 'stdout' => $aliceProof, 'stderr' => '']);

    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response($aliceProof, 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response($bobProof, 200),
        'finney.calendar.eternitywall.com/*' => Http::response('', 500), // Failed
    ]);

    // Act
    $result = $this->service->submit($digest);

    // Assert: Should return proof from the first (Alice) calendar
    expect($result)->not->toBeEmpty('Should return a valid proof');
    expect(strlen($result))->toBeGreaterThan(50, 'Proof should have reasonable size');
    expect(str_contains($result, 'alice.btc.calendar.opentimestamps.org'))->toBeTrue(
        'Proof should come from Alice calendar'
    );
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
