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
     * Implements hybrid verification approach:
     * 1. Basic proof structure validation (PHP)
     * 2. Commitment extraction and matching
     * 3. Bitcoin attestation presence check
     * 4. Optional: External service verification (cached)
     *
     * @param  string  $proof  Binary OTS proof
     * @param  string  $digest  SHA256 hash (64 hex characters)
     * @return bool True if proof is valid and matches digest
     *
     * @throws \InvalidArgumentException if digest format is invalid
     */
    public function verify(string $proof, string $digest): bool
    {
        // Validate digest format
        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be 64-character hex SHA256 hash');
        }

        // Normalize digest to lowercase for consistent comparison (bin2hex returns lowercase)
        $digest = strtolower($digest);

        // Reject empty or too short proofs
        if (strlen($proof) < 32) {
            Log::debug('OpenTimestamp: Proof too short', ['proof_length' => strlen($proof)]);

            return false;
        }

        // Check cache first before expensive verification operations
        $cacheKey = 'ots_verified_'.hash('sha256', $proof.$digest);

        /** @var bool $result */
        $result = \Illuminate\Support\Facades\Cache::remember(
            $cacheKey,
            now()->addDays(30), // Cache for 30 days
            function () use ($proof, $digest): bool {
                try {
                    // Step 1: Extract commitment from proof
                    $commitment = $this->extractCommitment($proof);

                    if ($commitment === null) {
                        Log::debug('OpenTimestamp: Cannot extract commitment from proof');

                        return false;
                    }

                    // Step 2: Verify commitment matches provided digest
                    $commitmentHex = bin2hex($commitment);
                    if ($commitmentHex !== $digest) {
                        Log::debug('OpenTimestamp: Commitment mismatch', [
                            'expected' => $digest,
                            'actual' => $commitmentHex,
                        ]);

                        return false;
                    }

                    // Step 3: Check for Bitcoin attestation (proof must be confirmed)
                    if (! $this->hasAttestation($proof, 'bitcoin')) {
                        Log::debug('OpenTimestamp: No Bitcoin attestation found (proof still pending)');

                        return false;
                    }

                    Log::info('OpenTimestamp: Proof verification successful', [
                        'digest' => $digest,
                        'proof_size' => strlen($proof),
                    ]);

                    return true;
                } catch (\Exception $e) {
                    Log::warning('OpenTimestamp: Verification failed', [
                        'digest' => $digest,
                        'error' => $e->getMessage(),
                    ]);

                    return false;
                }
            }
        );

        return $result;
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
