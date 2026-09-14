<?php

/**
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessExecutor;
use Illuminate\Support\Facades\Cache;
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
    private const string VERIFICATION_CACHE_VERSION = 'v4';

    private const int MAX_VERIFICATION_PROOF_BYTES = 1_048_576;

    private const int MAX_VERIFICATION_CACHE_TTL_SECONDS = 86_400;

    /**
     * @var ProcessExecutor External process executor
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
     * Uses the OpenTimestamps Python library for reliable calendar server interaction.
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

        $digest = strtolower($digest);

        // Check if Python and OTS script are available
        if (! $this->processExecutor->commandExists('python3')) {
            throw new RuntimeException(
                'Python3 not installed. Required for OTS script execution.'
            );
        }

        if (! file_exists(base_path('scripts/ots-stamp-hash.py'))) {
            throw new RuntimeException(
                'OTS submission script not found: scripts/ots-stamp-hash.py'
            );
        }

        Log::info('OpenTimestamp: Submitting digest via Python script', [
            'digest_hint' => $this->digestHint($digest),
        ]);

        // Use custom Python script that timestamps a pre-computed hash directly.
        // The standard `ots stamp -` command hashes its input, which would double-hash our merkle root.
        // Our script uses the opentimestamps Python library to create Timestamp(digest) directly.
        $scriptPath = base_path('scripts/ots-stamp-hash.py');

        try {
            // Execute: python3 ots-stamp-hash.py <hex_hash>
            // Script writes binary proof to stdout, status messages to stderr
            $result = $this->processExecutor->execute(
                ['python3', $scriptPath, $digest],
                null, // No stdin needed - hash is passed as argument
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
                        'OTS submission script failed with exit code %d: %s',
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
                'digest_hint' => $this->digestHint($digest),
                'proof_size' => strlen($proof),
            ]);

            return $proof;

        } catch (\Exception $e) {
            Log::error('OpenTimestamp: Submission failed', [
                'digest_hint' => $this->digestHint($digest),
                'error' => $this->sanitizeProcessMessage($e->getMessage()),
            ]);

            throw new RuntimeException(
                'Failed to submit timestamp: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Upgrade a pending proof through the focused Python helper.
     *
     * @param  string  $pendingProof  Binary OTS proof with pending attestations
     * @return string|null Binary upgraded proof with Bitcoin attestation, or null if pending
     */
    public function upgrade(string $pendingProof): ?string
    {
        if (! $this->processExecutor->commandExists('python3')) {
            Log::error('OpenTimestamp: python3 not installed for upgrade', [
                'message' => 'Rebuild the image with the reviewed, pinned OpenTimestamp dependency.',
            ]);

            return null;
        }

        $scriptPath = base_path('scripts/ots-upgrade.py');
        if (! file_exists($scriptPath)) {
            Log::error('OpenTimestamp: Upgrade script not found', [
                'script_path' => $scriptPath,
            ]);

            return null;
        }

        Log::debug('OpenTimestamp: Attempting proof upgrade', [
            'proof_size' => strlen($pendingProof),
        ]);

        $tempFile = $this->createSecureTempFile('ots_upgrade_');
        if ($tempFile === null) {
            Log::error('OpenTimestamp: Cannot create temp file for upgrade');

            return null;
        }

        try {
            $bytesWritten = file_put_contents($tempFile, $pendingProof, LOCK_EX);
            if ($bytesWritten === false) {
                Log::error('OpenTimestamp: Cannot write pending proof to temp file for upgrade', [
                    'proof_size' => strlen($pendingProof),
                ]);

                return null;
            }

            $result = $this->processExecutor->execute(
                ['python3', $scriptPath, $tempFile],
                null,
                10
            );

            if ($result['exitCode'] !== 0) {
                Log::debug('OpenTimestamp: No proof upgrade available', [
                    'exit_code' => $result['exitCode'],
                    'result' => $this->sanitizeProcessMessage((string) ($result['stderr'] ?? '')),
                ]);

                return null;
            }

            $upgradedProofBinary = file_get_contents($tempFile);
            if ($upgradedProofBinary === false || $pendingProof === $upgradedProofBinary) {
                Log::error('OpenTimestamp: Upgrade helper returned no changed proof');

                return null;
            }

            Log::info('OpenTimestamp: Proof upgraded', [
                'old_size' => strlen($pendingProof),
                'new_size' => strlen($upgradedProofBinary),
            ]);

            return $upgradedProofBinary;
        } finally {
            $this->cleanupTempFile($tempFile);
        }
    }

    /**
     * Verify OTS proof against message digest using Python verification script.
     *
     * IMPORTANT: This verification works WITHOUT a local Bitcoin node!
     * It uses the opentimestamps Python library to check proof validity by:
     * 1. Verifying the proof contains the correct digest/hash
     * 2. Checking for Bitcoin block attestations (= confirmed on blockchain)
     * 3. Validating the cryptographic proof structure
     *
     * This implementation uses our custom Python script (scripts/ots-verify.py)
     * which does NOT require a Bitcoin Core node running locally. The previous
     * CLI-based approach (`ots verify`) required a local Bitcoin node which is
     * not feasible in most development and production environments.
     *
     * CACHING: Successful verifications are cached for a bounded TTL and then checked
     * against the active chain again. Failures are not cached because proofs may upgrade.
     *
     * @param  string  $proof  Binary OTS proof (matches Activity model accessor/mutator)
     * @param  string  $digest  SHA256 hash (64 hex characters)
     * @return bool True if proof is cryptographically valid, false otherwise
     *
     * @throws \InvalidArgumentException if digest format is invalid
     *
     * @see scripts/ots-verify.py for verification implementation
     */
    public function verify(string $proof, string $digest): bool
    {
        // Validate digest format
        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be 64-character hex SHA256 hash');
        }

        // Normalize digest to lowercase
        $digest = strtolower($digest);

        if (strlen($proof) > self::MAX_VERIFICATION_PROOF_BYTES) {
            Log::warning('OpenTimestamp: Proof exceeds verification size limit', [
                'digest_hint' => $this->digestHint($digest),
                'proof_size' => strlen($proof),
                'maximum_proof_size' => self::MAX_VERIFICATION_PROOF_BYTES,
            ]);

            return false;
        }

        $bitcoinHeaderApiBases = config('services.opentimestamps.bitcoin_header_api_bases');
        if (! is_string($bitcoinHeaderApiBases) || trim($bitcoinHeaderApiBases) === '') {
            Log::error('OpenTimestamp: Bitcoin header APIs are not configured', [
                'digest_hint' => $this->digestHint($digest),
            ]);

            return false;
        }

        $configuredCacheTtl = config('services.opentimestamps.verification_cache_ttl_seconds');
        if (
            (! is_int($configuredCacheTtl) && ! (is_string($configuredCacheTtl) && ctype_digit($configuredCacheTtl)))
            || (int) $configuredCacheTtl <= 0
            || (int) $configuredCacheTtl > self::MAX_VERIFICATION_CACHE_TTL_SECONDS
        ) {
            Log::error('OpenTimestamp: Verification cache TTL is invalid', [
                'digest_hint' => $this->digestHint($digest),
            ]);

            return false;
        }
        $cacheTtlSeconds = (int) $configuredCacheTtl;

        // Check the bounded positive cache before starting the verifier process.
        // Do not reuse decisions made by earlier verifier versions. In particular,
        // v1 accepted Bitcoin attestations without checking their block headers, v2 did
        // not bind decisions to the proof/providers, and v3 cached chain state forever.
        $verificationContext = hash(
            'sha256',
            $proof."\0".$bitcoinHeaderApiBases."\0".$cacheTtlSeconds,
        );
        $cacheKey = 'ots:verified:'.self::VERIFICATION_CACHE_VERSION.":{$digest}:{$verificationContext}";
        $cachedVerification = Cache::get($cacheKey);
        if ($this->isTrustedCachedVerification($cachedVerification, $cacheKey, $cacheTtlSeconds)) {
            Log::debug('OpenTimestamp: Cache hit for verified proof', [
                'digest_hint' => $this->digestHint($digest),
            ]);

            return true;
        }
        if ($cachedVerification !== null) {
            Cache::forget($cacheKey);
            Log::warning('OpenTimestamp: Ignored invalid verification cache entry', [
                'digest_hint' => $this->digestHint($digest),
            ]);
        }

        // Check if Python 3 is installed
        if (! $this->processExecutor->commandExists('python3')) {
            Log::error('OpenTimestamp: python3 not installed', [
                'digest_hint' => $this->digestHint($digest),
                'message' => 'Install Python 3 for OTS verification',
            ]);

            return false;
        }

        // Check if verification script exists
        $scriptPath = base_path('scripts/ots-verify.py');
        if (! file_exists($scriptPath)) {
            Log::error('OpenTimestamp: Verification script not found', [
                'digest_hint' => $this->digestHint($digest),
                'script_path' => $scriptPath,
            ]);

            return false;
        }

        Log::debug('OpenTimestamp: Verifying proof with Python script', [
            'digest_hint' => $this->digestHint($digest),
            'proof_size' => strlen($proof),
        ]);

        // Write proof to temporary file
        $tempFile = $this->createSecureTempFile('ots_verify_');
        if ($tempFile === null) {
            Log::error('OpenTimestamp: Cannot create temp file for verification');

            return false;
        }

        try {
            $bytesWritten = file_put_contents($tempFile, $proof, LOCK_EX);

            if ($bytesWritten === false) {
                Log::error('OpenTimestamp: Failed to write proof to temp file', [
                    'digest_hint' => $this->digestHint($digest),
                ]);

                return false;
            }

            // Execute: python3 scripts/ots-verify.py <proof_file> <digest>
            // Exit code 0 = success, 1 = failed, 2 = error
            $result = $this->processExecutor->execute(
                ['python3', $scriptPath, $tempFile, $digest],
                null, // No stdin
                10, // 10 second timeout
                ['OTS_BITCOIN_HEADER_API_BASES' => $bitcoinHeaderApiBases],
            );

            // Check exit code (0 = success)
            if ($result['exitCode'] === 0) {
                Log::info('OpenTimestamp: Proof verification successful', [
                    'digest_hint' => $this->digestHint($digest),
                    'output' => $this->sanitizeProcessMessage((string) $result['stderr']),
                ]);

                // Revalidate periodically so a chain reorganization cannot leave a
                // previously successful provider observation trusted forever.
                $cacheValue = $this->createVerificationCacheValue($cacheKey);
                if ($cacheValue !== null) {
                    Cache::put($cacheKey, $cacheValue, $cacheTtlSeconds);
                } else {
                    Log::warning('OpenTimestamp: Verification result was not cached because the application key is unavailable', [
                        'digest_hint' => $this->digestHint($digest),
                    ]);
                }

                return true;
            }

            // Verification failed (exit code 1 = invalid proof, 2 = error)
            // NOTE: Do NOT cache failures - proof may be upgraded later
            Log::warning('OpenTimestamp: Proof verification failed', [
                'digest_hint' => $this->digestHint($digest),
                'exit_code' => $result['exitCode'],
                'stderr' => $this->sanitizeProcessMessage((string) $result['stderr']),
            ]);

            return false;
        } finally {
            $this->cleanupTempFile($tempFile);
        }
    }

    /**
     * @return array{verified_at: int, authenticator: string}|null
     */
    private function createVerificationCacheValue(string $cacheKey): ?array
    {
        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || $applicationKey === '') {
            return null;
        }

        $verifiedAt = now()->getTimestamp();

        return [
            'verified_at' => $verifiedAt,
            'authenticator' => hash_hmac('sha256', $cacheKey."\0".$verifiedAt, $applicationKey),
        ];
    }

    private function isTrustedCachedVerification(
        mixed $cachedVerification,
        string $cacheKey,
        int $cacheTtlSeconds,
    ): bool {
        if (
            ! is_array($cachedVerification)
            || ! isset($cachedVerification['verified_at'], $cachedVerification['authenticator'])
            || ! is_int($cachedVerification['verified_at'])
            || ! is_string($cachedVerification['authenticator'])
        ) {
            return false;
        }

        $verifiedAt = $cachedVerification['verified_at'];
        $currentTimestamp = now()->getTimestamp();
        if ($verifiedAt > $currentTimestamp || $currentTimestamp - $verifiedAt >= $cacheTtlSeconds) {
            return false;
        }

        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || $applicationKey === '') {
            return false;
        }

        $expectedAuthenticator = hash_hmac(
            'sha256',
            $cacheKey."\0".$verifiedAt,
            $applicationKey,
        );

        return hash_equals($expectedAuthenticator, $cachedVerification['authenticator']);
    }

    /**
     * Create a temporary proof file with restrictive permissions.
     */
    private function createSecureTempFile(string $prefix): ?string
    {
        $previousUmask = umask(0o077);

        try {
            $tempFile = tempnam(sys_get_temp_dir(), $prefix);
        } finally {
            umask($previousUmask);
        }

        if ($tempFile === false) {
            return null;
        }

        if (! chmod($tempFile, 0o600)) {
            @unlink($tempFile);

            return null;
        }

        return $tempFile;
    }

    /**
     * Remove a temporary proof file if it still exists.
     */
    private function cleanupTempFile(?string $tempFile): void
    {
        if ($tempFile === null || $tempFile === '') {
            return;
        }

        clearstatcache(true, $tempFile);

        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }

    /**
     * Reduce log exposure for digests while retaining traceability.
     */
    private function digestHint(string $digest): string
    {
        return substr($digest, 0, 12);
    }

    /**
     * Normalize and truncate process output before logging.
     */
    private function sanitizeProcessMessage(string $message, int $maxLength = 500): string
    {
        $sanitized = trim(preg_replace('/[[:cntrl:]]+/', ' ', $message) ?? '');

        $sanitized = preg_replace('/\b[a-f0-9]{64}\b/i', '[redacted-digest]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('#/(?:[^\s/]*/)*ots_(?:verify|upgrade)_[^\s]*#', '[redacted-temp-file]', $sanitized) ?? $sanitized;

        if ($sanitized === '') {
            return 'No error details available';
        }

        if (strlen($sanitized) > $maxLength) {
            return substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }
}
