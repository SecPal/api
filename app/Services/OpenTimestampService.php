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
     * @var ProcessExecutor CLI process executor
     */
    private ProcessExecutor $processExecutor;

    public function __construct(ProcessExecutor $processExecutor)
    {
        $this->processExecutor = $processExecutor;
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
                // Sanitize and truncate stderr for log safety (calendar errors might contain HTML/special chars)
                $stderr = trim((string) ($result['stderr'] ?? ''));
                if ($stderr === '') {
                    $stderr = 'No error details available';
                } else {
                    // Truncate to 500 chars for log readability, escape % for sprintf
                    $stderr = substr($stderr, 0, 500);
                    $stderr = str_replace('%', '%%', $stderr);
                }

                throw new RuntimeException(
                    sprintf(
                        'OTS stamp failed with exit code %d: %s',
                        $result['exitCode'],
                        $stderr
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
     * Upgrade pending proof to confirmed proof using OTS CLI.
     *
     * Uses the official `ots upgrade` CLI command to reliably upgrade proofs.
     * This avoids commitment extraction issues with the HTTP API approach.
     *
     * @param  string  $pendingProof  Binary OTS proof with pending attestations
     * @return string|null Binary upgraded proof with Bitcoin attestation, or null if pending
     */
    public function upgrade(string $pendingProof): ?string
    {
        // Check if ots CLI is installed
        if (! $this->processExecutor->commandExists('ots')) {
            Log::error('OpenTimestamp: ots CLI not installed for upgrade', [
                'message' => 'Install with: pip install opentimestamps-client',
            ]);

            return null;
        }

        Log::debug('OpenTimestamp: Attempting upgrade via CLI', [
            'proof_size' => strlen($pendingProof),
        ]);

        // Write proof to temporary file (ots CLI requires file input)
        $tempFile = tempnam(sys_get_temp_dir(), 'ots_upgrade_');
        if ($tempFile === false) {
            Log::error('OpenTimestamp: Cannot create temp file for upgrade');

            return null;
        }

        try {
            $bytesWritten = file_put_contents($tempFile, $pendingProof);
            if ($bytesWritten === false) {
                Log::error('OpenTimestamp: Cannot write pending proof to temp file for upgrade', [
                    'temp_file' => $tempFile,
                    'proof_size' => strlen($pendingProof),
                ]);

                return null;
            }

            // Execute: ots upgrade <file>
            // This modifies the file in-place if upgrade is available
            $result = $this->processExecutor->execute(
                ['ots', 'upgrade', $tempFile],
                null, // No stdin
                10 // 10 second timeout
            );

            // Read the (potentially upgraded) proof back
            $upgradedProofBinary = file_get_contents($tempFile);
            if ($upgradedProofBinary === false) {
                Log::error('OpenTimestamp: Cannot read upgraded proof from temp file');

                return null;
            }

            // Check if proof was actually upgraded (size should increase)
            $proofChanged = ($pendingProof !== $upgradedProofBinary);

            if ($result['exitCode'] === 0 && $proofChanged) {
                // Verify it has Bitcoin attestation
                if ($this->hasAttestation($upgradedProofBinary, 'bitcoin')) {
                    Log::info('OpenTimestamp: Proof upgraded via CLI', [
                        'old_size' => strlen($pendingProof),
                        'new_size' => strlen($upgradedProofBinary),
                        'output' => trim($result['stdout']),
                    ]);

                    // Return binary proof (matches Activity model accessor/mutator)
                    return $upgradedProofBinary;
                }
            }

            // Upgrade not yet available or failed
            Log::debug('OpenTimestamp: No upgrade available yet (CLI)', [
                'exit_code' => $result['exitCode'],
                'proof_changed' => $proofChanged,
                'output' => trim($result['stdout']),
            ]);

            return null;
        } finally {
            // Always cleanup temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
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
     * @param  string  $proof  Binary OTS proof (matches Activity model accessor/mutator)
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

        // Write proof to temporary file (ots verify requires file input)
        $tempFile = tempnam(sys_get_temp_dir(), 'ots_verify_');
        if ($tempFile === false) {
            Log::error('OpenTimestamp: Cannot create temp file for verification');

            return false;
        }

        try {
            $bytesWritten = file_put_contents($tempFile, $proof);

            if ($bytesWritten === false) {
                Log::error('OpenTimestamp: Failed to write proof to temp file for verification', [
                    'digest' => $digest,
                    'temp_file' => $tempFile,
                ]);

                return false;
            }

            // Execute: ots verify <proof-file> -d <hash>
            // Note: File must come FIRST, then -d option (positional arg before options works in Python argparse)
            $result = $this->processExecutor->execute(
                ['ots', 'verify', $tempFile, '-d', $digest],
                null, // No stdin
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
        } finally {
            // Always cleanup temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
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
