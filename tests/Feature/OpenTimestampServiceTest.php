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
 * Test OpenTimestamp service integration.
 *
 * Tests submission and upgrade of timestamps via REST API.
 *
 * @see App\Services\OpenTimestampService
 */
uses()->group('feature');

beforeEach(function () {
    // Mock ProcessExecutor to avoid CLI dependency
    $this->mockExecutor = Mockery::mock(ProcessExecutor::class);
    $this->app->instance(ProcessExecutor::class, $this->mockExecutor);

    $this->service = app(OpenTimestampService::class);
});

test('submit creates pending proof', function () {
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
    expect($proof)->not->toBeEmpty();
    expect($proof)->toBeString();
    expect(strlen($proof))->toBeGreaterThan(20); // Minimal proof size

    // Verify HTTP requests were made
    Http::assertSentCount(3); // 3 calendar servers
});

test('submit fails if insufficient calendars respond', function () {
    // Arrange: Mock calendar servers - only 1 responds
    Http::fake([
        'alice.btc.calendar.opentimestamps.org/*' => Http::response('proof1', 200),
        'bob.btc.calendar.opentimestamps.org/*' => Http::response('', 500),
        'finney.calendar.eternitywall.com/*' => Http::response('', 500),
    ]);

    $merkleRoot = hash('sha256', 'test-merkle-root');

    // Act & Assert: Should throw exception
    expect(fn () => $this->service->submit($merkleRoot))
        ->toThrow(\RuntimeException::class, 'Failed to submit timestamp: only 1 of 3 calendars responded');
});

test('upgrade returns null if not yet confirmed', function () {
    // Arrange: Create minimal pending proof
    $pendingProof = createPendingProof();

    Http::fake([
        '*/timestamp/*' => Http::response('', 404), // Not yet confirmed
    ]);

    // Act: Attempt upgrade
    $upgradedProof = $this->service->upgrade($pendingProof);

    // Assert: No upgrade available
    expect($upgradedProof)->toBeNull();
});

test('upgrade returns confirmed proof when available', function () {
    // Arrange: Create pending proof
    $pendingProof = createPendingProof();

    // Mock confirmed proof with Bitcoin attestation
    $confirmedProof = createConfirmedProof();

    Http::fake([
        '*/timestamp/*' => Http::response($confirmedProof, 200),
    ]);

    // Act: Upgrade
    $upgraded = $this->service->upgrade($pendingProof);

    // Assert: Proof upgraded
    expect($upgraded)->not->toBeNull();
    expect($upgraded)->not->toBe($pendingProof);
    // Check that proof contains Bitcoin attestation signature bytes
    expect($upgraded)->toContain("\x05\x88\x96\x0d");
});

test('verify returns false for invalid proof', function () {
    // Arrange: Invalid proof
    $invalidProof = 'invalid-proof-data';
    $merkleRoot = hash('sha256', 'test');

    // Mock: CLI returns failure
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

    // Act & Assert
    $result = $this->service->verify($invalidProof, $merkleRoot);

    expect($result)->toBeFalse();
});

test('verify returns false when cli not available', function () {
    // Arrange: ots CLI not installed
    $merkleRoot = hash('sha256', 'test-root');
    $merkleRootBytes = hex2bin($merkleRoot);

    // Create proof: merkle root (32 bytes) + Bitcoin attestation signature
    $confirmedProof = $merkleRootBytes."\x05\x88\x96\x0d\x73\xd7\x19\x01\x03\xe3\x93\x10";

    // Mock: CLI not available
    $this->mockExecutor
        ->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(false);

    // Act: Verify
    $result = $this->service->verify($confirmedProof, $merkleRoot);

    // Assert: Should return false when CLI not installed
    expect($result)->toBeFalse('verify() should return false when ots CLI not installed');
});

test('verify checks for bitcoin attestation', function () {
    // Arrange: Proof without Bitcoin attestation (still pending)
    $merkleRoot = hash('sha256', 'test-root');
    $pendingProof = createPendingProof();

    // Mock: CLI returns "not yet confirmed" error
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

    // Act & Assert: Should return false (no Bitcoin attestation)
    $result = $this->service->verify($pendingProof, $merkleRoot);
    expect($result)->toBeFalse();
});

/**
 * Create minimal pending proof for testing.
 */
function createPendingProof(): string
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
function createConfirmedProof(?string $merkleRoot = null): string
{
    // OTS proof format: Operations + BitcoinBlockHeaderAttestation
    return hex2bin(
        '00'. // OpSHA256
            '0588960d73d7190103'. // Bitcoin block 428648 attestation
            'e39310' // Block height encoded
    );
}
