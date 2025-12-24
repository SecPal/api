<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OpenTimestamp service for blockchain-anchored timestamping.
 *
 * Provides Bitcoin blockchain anchoring via OpenTimestamp calendar servers.
 * Uses REST API for submission, upgrade, and verification of proofs.
 *
 * OTS Proof Format (binary):
 * - Operations (OpSHA256, OpPrepend, OpAppend, etc.)
 * - Attestations (PendingAttestation, BitcoinBlockHeaderAttestation)
 *
 * @see https://opentimestamps.org/
 * @see ADR-010 Section 6: OpenTimestamp Integration
 */
class OpenTimestampService
{
    /**
     * Minimum calendar responses required for successful submission.
     */
    private const MIN_CALENDAR_RESPONSES = 2;

    /**
     * @var array<string> Calendar server URLs
     */
    private array $calendarUrls;

    /**
     * @var int Request timeout in seconds
     */
    private int $timeout;

    public function __construct()
    {
        /** @var array<string> $urls */
        $urls = config('opentimestamp.calendar_urls', [
            'https://alice.btc.calendar.opentimestamps.org',
            'https://bob.btc.calendar.opentimestamps.org',
            'https://finney.calendar.eternitywall.com',
        ]);
        $this->calendarUrls = $urls;

        /** @var int $timeout */
        $timeout = config('opentimestamp.timeout', 5);
        $this->timeout = $timeout;
    }

    /**
     * Submit message digest to OpenTimestamp calendars.
     *
     * Creates pending proof that will be upgraded when Bitcoin block confirms.
     *
     * @param  string  $digest  SHA256 hash (64 hex characters)
     * @return string Binary OTS proof (pending attestation)
     *
     * @throws RuntimeException if insufficient calendars respond
     */
    public function submit(string $digest): string
    {
        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be 64-character hex SHA256 hash');
        }

        $digestBytes = hex2bin($digest);
        if ($digestBytes === false) {
            throw new \InvalidArgumentException('Invalid hex digest');
        }

        Log::info('OpenTimestamp: Submitting digest', ['digest' => $digest]);

        // Submit to all calendars in parallel
        $responses = [];
        $http = Http::timeout($this->timeout)->withHeaders([
            'Accept' => 'application/octet-stream',
            'Content-Type' => 'application/octet-stream',
            'User-Agent' => 'SecPal-Laravel-OTS/1.0',
        ]);

        // Use HTTP pool for parallel calendar submissions
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($calendarUrl) => $pool
                ->timeout($this->timeout)
                ->post("{$calendarUrl}/timestamp/{$digest}"),
            $this->calendarUrls
        ));

        $successfulResponses = [];
        foreach ($responses as $index => $response) {
            $calendarUrl = $this->calendarUrls[$index];

            if ($response instanceof \Exception) {
                Log::warning('OpenTimestamp: Calendar error', [
                    'calendar' => $calendarUrl,
                    'error' => $response->getMessage(),
                ]);

                continue;
            }

            if ($response->successful()) {
                $successfulResponses[] = $response->body();
                Log::debug('OpenTimestamp: Calendar responded', ['calendar' => $calendarUrl]);
            } else {
                Log::warning('OpenTimestamp: Calendar failed', [
                    'calendar' => $calendarUrl,
                    'status' => $response->status(),
                ]);
            }
        }

        // Require minimum responses
        if (count($successfulResponses) < self::MIN_CALENDAR_RESPONSES) {
            throw new RuntimeException(
                sprintf(
                    'Failed to submit timestamp: only %d of %d calendars responded (minimum: %d)',
                    count($successfulResponses),
                    count($this->calendarUrls),
                    self::MIN_CALENDAR_RESPONSES
                )
            );
        }

        // Merge responses into single proof
        $proof = $this->mergeProofs($successfulResponses, $digestBytes);

        Log::info('OpenTimestamp: Submission successful', [
            'digest' => $digest,
            'calendars' => count($responses),
            'proof_size' => strlen($proof),
        ]);

        return $proof;
    }

    /**
     * Upgrade pending proof to confirmed proof.
     *
     * Queries calendars for Bitcoin block confirmation. Returns null if not yet confirmed.
     *
     * @param  string  $pendingProof  Binary OTS proof with pending attestations
     * @return string|null Upgraded proof with Bitcoin attestation, or null if pending
     */
    public function upgrade(string $pendingProof): ?string
    {
        // Parse proof to extract commitment
        $commitment = $this->extractCommitment($pendingProof);

        if ($commitment === null) {
            Log::warning('OpenTimestamp: Cannot extract commitment from proof');

            return null;
        }

        $commitmentHex = bin2hex($commitment);

        Log::debug('OpenTimestamp: Attempting upgrade', ['commitment' => $commitmentHex]);

        // Query calendars for upgraded proof
        $http = Http::timeout($this->timeout)->withHeaders([
            'Accept' => 'application/octet-stream',
            'User-Agent' => 'SecPal-Laravel-OTS/1.0',
        ]);

        foreach ($this->calendarUrls as $calendarUrl) {
            try {
                $response = $http->get("{$calendarUrl}/timestamp/{$commitmentHex}");

                if ($response->successful()) {
                    $upgradedProof = $response->body();

                    // Check if proof contains Bitcoin attestation
                    if ($this->hasAttestation($upgradedProof, 'bitcoin')) {
                        Log::info('OpenTimestamp: Proof upgraded', [
                            'commitment' => $commitmentHex,
                            'calendar' => $calendarUrl,
                        ]);

                        return $upgradedProof;
                    }
                }
            } catch (\Exception $e) {
                Log::debug('OpenTimestamp: Calendar upgrade failed', [
                    'calendar' => $calendarUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::debug('OpenTimestamp: No upgrade available yet');

        return null;
    }

    /**
     * Verify OTS proof against message digest.
     *
     * SECURITY: Local proof verification is INTENTIONALLY DISABLED.
     *
     * The previous "hybrid approach" implementation (PR #413) was vulnerable to
     * trivial proof forgery attacks. An attacker could construct arbitrary bytes
     * matching our heuristic checks without any blockchain anchoring:
     *
     *   $fakeProof = "OpenTimestamps proof\0" . $digest_bytes . $padding . $magic_bytes;
     *
     * Critical security flaws in the hybrid approach:
     * 1. extractCommitment() blindly extracts first 32 bytes (no operation tree parsing)
     * 2. hasAttestation() only checks substring match (no cryptographic validation)
     * 3. No Bitcoin blockchain cross-verification (block height, Merkle proof)
     * 4. No operation chain validation (SHA256 operations not verified)
     *
     * These issues were identified in Copilot security review (PR #413, Comment #5).
     *
     * CORRECT IMPLEMENTATION requires:
     * - Full OTS proof parser (operation tree traversal)
     * - Cryptographic validation of operation chain
     * - Bitcoin blockchain attestation verification (block height + Merkle proof)
     * - OR delegation to vetted OTS verification service/library
     *
     * Until secure implementation is available, verification FAILS CLOSED for security.
     *
     * @param  string  $proof  Binary OTS proof (currently ignored)
     * @param  string  $digest  SHA256 hash (64 hex characters)
     * @return bool Always returns false until secure implementation available
     *
     * @throws \InvalidArgumentException if digest format is invalid
     *
     * @see Issue #412 for secure implementation requirements
     * @see https://github.com/opentimestamps/opentimestamps-client for reference implementation
     */
    public function verify(string $proof, string $digest): bool
    {
        // Validate digest format
        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be 64-character hex SHA256 hash');
        }

        Log::warning('OpenTimestamp: Local proof verification is disabled due to security concerns', [
            'digest' => $digest,
            'proof_size' => strlen($proof),
            'reason' => 'Hybrid approach vulnerable to trivial proof forgery (see PR #413 Copilot review)',
            'issue' => '#412',
        ]);

        return false;
    }

    /**
     * Merge multiple calendar proofs into single proof.
     *
     * @param  array<string>  $proofs  Binary calendar responses
     * @param  string  $digest  Original digest bytes
     */
    private function mergeProofs(array $proofs, string $digest): string
    {
        if ($proofs === []) {
            throw new RuntimeException('OpenTimestamp: No proofs provided for merging');
        }

        // For now, return first proof
        // TODO: Implement proper proof merging (combine attestations)
        return $proofs[0];
    }

    /**
     * Extract commitment (message digest) from OTS proof.
     *
     * Parses binary proof structure to find original commitment.
     * NOTE: This is still a simplified implementation:
     * - If the standard "OpenTimestamps proof\0" header is present, skip it
     *   and extract the next 32 bytes as the commitment
     * - Otherwise, use the first 32 bytes (legacy behavior for existing tests)
     * A full OTS parser would properly traverse the operation tree.
     *
     * @param  string  $proof  Binary OTS proof
     * @return string|null Commitment bytes, or null if parsing fails
     */
    private function extractCommitment(string $proof): ?string
    {
        // Check for standard OpenTimestamps header
        $offset = 0;
        $header = "OpenTimestamps proof\x00";

        if (str_starts_with($proof, $header)) {
            $offset = strlen($header);
        }

        if (strlen($proof) < $offset + 32) {
            return null;
        }

        // For now, just return first 32 bytes after header
        // TODO: Implement full OTS proof parser to extract actual commitment
        return substr($proof, $offset, 32);
    }

    /**
     * Check if proof contains specific attestation type.
     *
     * @param  string  $proof  Binary OTS proof
     * @param  string  $type  Attestation type ('bitcoin', 'litecoin', 'pending')
     */
    private function hasAttestation(string $proof, string $type): bool
    {
        // Bitcoin attestation: OpCode 0x05 0x88 0x96 0x0d 0x73 0xd7 0x19 0x01 0x03
        // Simplified check - looks for full 9-byte Bitcoin attestation signature
        if ($type === 'bitcoin') {
            return str_contains($proof, "\x05\x88\x96\x0d\x73\xd7\x19\x01\x03");
        }

        // Pending attestation: OpCode 0x83
        if ($type === 'pending') {
            return str_contains($proof, "\x83");
        }

        return false;
    }
}
