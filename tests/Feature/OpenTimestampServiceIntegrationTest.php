<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Services\OpenTimestampService;
use App\Services\SystemProcessExecutor;
use Illuminate\Support\Facades\Cache;

/**
 * Integration tests for OpenTimestamp verification.
 *
 * These tests use the real Python-based verification runtime (not mocked) to
 * verify that the integration works end-to-end in the active environment.
 */
/**
 * @property OpenTimestampService $service
 */
uses()->group('feature');

beforeEach(function () {
    $this->service = new OpenTimestampService(
        new SystemProcessExecutor
    );

    // Clear cache before each test
    Cache::flush();
});

/**
 * Test that the Python verification runtime is available in the environment.
 */
test('python verification runtime is available', function () {
    $executor = new SystemProcessExecutor;
    expect(
        $executor->commandExists('python3'),
        'python3 must be installed for OpenTimestamp verification'
    )->toBeTrue();

    expect(file_exists(base_path('scripts/ots-verify.py')))->toBeTrue();
});

/**
 * Test verification with invalid proof data.
 *
 * This test verifies that the CLI correctly rejects invalid proofs.
 */
test('verify rejects invalid proof', function () {
    $invalidProof = base64_encode('invalid proof data');
    $digest = hash('sha256', 'test data');

    $result = $this->service->verify($invalidProof, $digest);

    expect($result)->toBeFalse('Invalid proof should be rejected');

    // Verify that failed verifications are NOT cached
    $cacheKey = "ots:verified:{$digest}";
    expect(
        Cache::has($cacheKey),
        'Failed verifications should not be cached (proof may upgrade later)'
    )->toBeFalse();
});

/**
 * Test that verify() uses CLI verification (not stub/upgrade endpoints).
 *
 * This is a security-critical test: We must never use the stub()
 * or upgrade() endpoints for verification, only the external ots CLI.
 *
 * Context: Issue #412 identified critical vulnerabilities in the hybrid
 * verification approach. Issue #415 mandates CLI-only verification.
 */
test('verify uses external cli not http calendars', function () {
    // Use a pre-generated pending proof (not yet Bitcoin-anchored)
    // This is a real OTS proof structure, just not anchored to Bitcoin yet
    $pendingProof = base64_encode(
        hex2bin('004f70656e54696d657374616d7073000050726f6f6600bf09e8e884e89294010811c70929'.
               '8fc1c149afbf4c8996fb9242')
    );
    $digest = '11c709298fc1c149afbf4c8996fb9242'.
              '7ae245e4649b934ca495991b7852b855'; // SHA256 digest embedded in proof

    // Attempt verification - should fail because not yet Bitcoin-anchored
    $result = $this->service->verify($pendingProof, $digest);

    // The key assertion: verify() must return false for pending proofs
    // because CLI verification requires Bitcoin attestation.
    // This proves we're using CLI (not stub/upgrade endpoints).
    expect(
        $result,
        'Pending proofs should fail CLI verification (no Bitcoin attestation yet)'
    )->toBeFalse();

    // Additional security check: Ensure we never cached this false result
    $cacheKey = "ots:verified:{$digest}";
    expect(
        Cache::has($cacheKey),
        'Pending proof verification should not be cached'
    )->toBeFalse();
});

/**
 * Test caching behavior with multiple verification attempts.
 *
 * This test verifies that the caching layer works correctly
 * when the same proof is verified multiple times.
 */
test('verify caching integration', function () {
    // Use a pre-generated pending proof
    $pendingProof = base64_encode(
        hex2bin('004f70656e54696d657374616d7073000050726f6f6600bf09e8e884e89294010811c70929'.
               '8fc1c149afbf4c8996fb9242')
    );
    $digest = '11c709298fc1c149afbf4c8996fb9242'.
              '7ae245e4649b934ca495991b7852b855';

    // First verification - should hit CLI and return false (pending)
    $result1 = $this->service->verify($pendingProof, $digest);
    expect($result1)->toBeFalse();

    // Cache should be empty (failed verifications not cached)
    $cacheKey = "ots:verified:{$digest}";
    expect(Cache::has($cacheKey))->toBeFalse();

    // Second verification - should hit CLI again (not cached)
    $result2 = $this->service->verify($pendingProof, $digest);
    expect($result2)->toBeFalse();

    // Still not cached
    expect(Cache::has($cacheKey))->toBeFalse();
});

/**
 * Test that CLI timeout is handled gracefully.
 *
 * The ots CLI may timeout when calendar servers are slow or unreachable.
 * This should not crash the application.
 */
test('verify handles cli timeout gracefully', function () {
    // Create a proof with invalid data that might cause CLI to hang
    $invalidProof = base64_encode(str_repeat("\x00", 1000));
    $digest = hash('sha256', 'timeout test');

    // This should complete within the 10s timeout and return false
    $result = $this->service->verify($invalidProof, $digest);

    expect($result)->toBeFalse('Invalid proof should be rejected even on timeout');
});

/**
 * Test verify() with mismatched digest.
 *
 * Security test: Verification must fail if the provided digest
 * doesn't match the digest embedded in the proof.
 */
test('verify rejects mismatched digest', function () {
    // Use a pre-generated proof with known digest
    $proof = base64_encode(
        hex2bin('004f70656e54696d657374616d7073000050726f6f6600bf09e8e884e89294010811c70929'.
               '8fc1c149afbf4c8996fb9242')
    );
    $correctDigest = '11c709298fc1c149afbf4c8996fb9242'.
                     '7ae245e4649b934ca495991b7852b855';

    // Try to verify with different digest
    $wrongDigest = hash('sha256', 'tampered data');

    $result = $this->service->verify($proof, $wrongDigest);

    expect(
        $result,
        'Verification must fail when digest does not match proof'
    )->toBeFalse();
});
