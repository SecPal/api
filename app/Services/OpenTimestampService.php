<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessExecutor;
use Illuminate\Support\Facades\Cache;
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
     * @var array<string> Calendar server URLs
     */
    private array $calendarUrls;

    /**
     * @var int Request timeout in seconds
     */
    private int $timeout;

    /**
     * @var ProcessExecutor CLI process executor
     */
    private ProcessExecutor $processExecutor;

    public function __construct(ProcessExecutor $processExecutor)
    {
        $this->processExecutor = $processExecutor;

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
     * Uses `ots stamp` CLI command for reliable calendar server interaction.
     *
     * @param  string  $digest  SHA256 hash (64 hex characters)
     * @return string Binary OTS proof (pending attestation)
     *
     * @throws RuntimeException if submission fails
     */
    public function submit(string $digest): string
    {
        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be 64-character hex SHA256 hash');
        }

        // Check if ots CLI is installed
        if (! $this->processExecutor->commandExists('ots')) {
            throw new RuntimeException(
                'OpenTimestamp CLI not installed. Install with: pip install opentimestamps-client'
            );
        }

        Log::info('OpenTimestamp: Submitting digest via CLI', ['digest' => $digest]);

        // Use ots CLI to stamp the hash
        // ots stamp reads from stdin and writes proof to stdout
        $digestBytes = hex2bin($digest);
        if ($digestBytes === false) {
            throw new \InvalidArgumentException('Invalid hex digest');
        }

        try {
            // Execute: echo <bytes> | ots stamp -
            $result = $this->processExecutor->execute(
                ['ots', 'stamp', '-'],
                $digestBytes,
                15 // 15 second timeout for calendar submissions
            );

            if ($result['exitCode'] !== 0) {
                throw new RuntimeException(
                    sprintf(
                        'OTS stamp failed with exit code %d: %s',
                        $result['exitCode'],
                        $result['stderr']
                    )
                );
            }

            $proof = $result['stdout'];

            if (empty($proof)) {
                throw new RuntimeException('OTS stamp returned empty proof');
            }

            Log::info('OpenTimestamp: Submission successful', [
                'digest' => $digest,
                'proof_size' => strlen($proof),
            ]);

            return $proof;

        } catch (\Exception $e) {
            Log::error('OpenTimestamp: Submission failed', [
                'digest' => $digest,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Failed to submit timestamp: '.$e->getMessage(),
                0,
                $e
            );
        }
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

                /** @var \Illuminate\Http\Client\Response $response */
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
     * Verify OTS proof against message digest using OpenTimestamps CLI.
     *
     * SECURITY: Delegates verification to official `ots verify` CLI tool.
     *
     * This implementation uses the vetted OpenTimestamps client for cryptographically
     * sound verification, avoiding the security flaws of the previous "hybrid approach"
     * (see PR #413 review for details on proof forgery vulnerabilities).
     *
     * The official OTS CLI performs:
     * - Full operation tree parsing
     * - Cryptographic validation of operation chain
     * - Bitcoin blockchain attestation verification (block height + Merkle proof)
     * - Cross-check with actual Bitcoin transaction data
     *
     * CACHING: Successful verifications are cached forever (proofs are immutable once
     * Bitcoin-anchored). Failed verifications are NOT cached (proof may be upgraded later).
     *
     * Installation: pip install opentimestamps-client
     * Docs: https://github.com/opentimestamps/opentimestamps-client
     *
     * @param  string  $proof  Binary OTS proof
     * @param  string  $digest  SHA256 hash (64 hex characters)
     * @return bool True if proof is cryptographically valid, false otherwise
     *
     * @throws \InvalidArgumentException if digest format is invalid
     *
     * @see Issue #415 for secure implementation requirements
     * @see https://github.com/opentimestamps/opentimestamps-client
     */
    public function verify(string $proof, string $digest): bool
    {
        // Validate digest format
        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be 64-character hex SHA256 hash');
        }

        // Normalize digest to lowercase (OTS CLI expects lowercase)
        $digest = strtolower($digest);

        // Check cache first (immutable once verified)
        $cacheKey = "ots:verified:{$digest}";
        if (Cache::has($cacheKey)) {
            Log::debug('OpenTimestamp: Cache hit for verified proof', [
                'digest' => $digest,
            ]);

            return (bool) Cache::get($cacheKey);
        }

        // Check if ots CLI is installed
        if (! $this->processExecutor->commandExists('ots')) {
            Log::error('OpenTimestamp: ots CLI not installed', [
                'digest' => $digest,
                'message' => 'Install with: pip install opentimestamps-client',
            ]);

            return false;
        }

        Log::debug('OpenTimestamp: Verifying proof with CLI', [
            'digest' => $digest,
            'proof_size' => strlen($proof),
        ]);

        // Execute: ots verify --digest <hash>
        // Proof is provided via stdin
        $result = $this->processExecutor->execute(
            ['ots', 'verify', '--digest', $digest],
            $proof,
            10 // 10 second timeout
        );

        // Check exit code (0 = success, non-zero = failure)
        if ($result['exitCode'] === 0) {
            Log::info('OpenTimestamp: Proof verification successful', [
                'digest' => $digest,
                'output' => trim($result['stdout']),
            ]);

            // Cache positive result forever (proofs are immutable once Bitcoin-anchored)
            Cache::forever($cacheKey, true);

            return true;
        }

        // Verification failed
        // NOTE: Do NOT cache failures - proof may be upgraded later
        Log::warning('OpenTimestamp: Proof verification failed', [
            'digest' => $digest,
            'exit_code' => $result['exitCode'],
            'stdout' => trim($result['stdout']),
            'stderr' => trim($result['stderr']),
        ]);

        return false;
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
