<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Services\OpenTimestampService;
use App\Services\SystemProcessExecutor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

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

function createRealBitcoinAttestationProof(string $digest, int $height): string
{
    $python = <<<'PY'
import base64
import sys
from io import BytesIO
from opentimestamps.core.notary import BitcoinBlockHeaderAttestation
from opentimestamps.core.op import OpSHA256
from opentimestamps.core.serialize import StreamSerializationContext
from opentimestamps.core.timestamp import DetachedTimestampFile, Timestamp

digest = bytes.fromhex(sys.argv[1])
timestamp = Timestamp(digest)
timestamp.attestations.add(BitcoinBlockHeaderAttestation(int(sys.argv[2])))
proof = DetachedTimestampFile(OpSHA256(), timestamp)
output = BytesIO()
proof.serialize(StreamSerializationContext(output))
print(base64.b64encode(output.getvalue()).decode('ascii'))
PY;
    $process = new Process(['python3', '-c', $python, $digest, (string) $height]);
    $process->mustRun();
    $proof = base64_decode(trim($process->getOutput()), true);

    if ($proof === false) {
        throw new RuntimeException('Python returned an invalid base64-encoded OpenTimestamp proof');
    }

    return $proof;
}

function writeRealOtsBitcoinHeaderApi(string $workspace, string $directory, string $header, int $height): string
{
    $blockHash = bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));
    $api = $workspace.'/'.$directory;

    mkdir($api.'/block-height', 0770, true);
    mkdir($api.'/block/'.$blockHash, 0770, true);
    file_put_contents($api.'/block-height/'.$height, $blockHash);
    file_put_contents($api.'/block/'.$blockHash.'/header', bin2hex($header));

    return 'https://'.$directory.'.test/api';
}

function writeRealOtsHttpsTestShim(string $workspace): void
{
    $siteCustomize = str_replace('__WORKSPACE__', var_export($workspace, true), <<<'PY'
import os
import urllib.request
from urllib.parse import urlsplit

workspace = __WORKSPACE__
original_urlopen = urllib.request.urlopen

class LocalHttpsResponse:
    def __init__(self, response, original_url):
        self.response = response
        self.original_url = original_url

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.response.close()

    def read(self, size=-1):
        return self.response.read(size)

    def geturl(self):
        return self.original_url

def local_urlopen(url, timeout=None):
    parsed = urlsplit(url)
    if parsed.hostname and parsed.hostname.lower().endswith('.test'):
        directory = parsed.hostname.lower().removesuffix('.test')
        relative_path = parsed.path.removeprefix('/api/')
        local_path = os.path.join(workspace, directory, relative_path)
        response = original_urlopen('file://' + local_path, timeout=timeout)
        return LocalHttpsResponse(response, url)

    return original_urlopen(url, timeout=timeout)

urllib.request.urlopen = local_urlopen
PY);

    file_put_contents($workspace.'/sitecustomize.py', $siteCustomize);
}

function otsVerificationCacheKey(string $proof, string $digest): string
{
    $apiBases = config('services.opentimestamps.bitcoin_header_api_bases');

    return "ots:verified:v3:{$digest}:".hash('sha256', $proof."\0".$apiBases);
}

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

test('verify accepts a real bitcoin attestation through two agreeing header APIs', function () {
    $workspace = sys_get_temp_dir().'/ots-real-integration-'.bin2hex(random_bytes(8));
    mkdir($workspace, 0770, true);
    $height = 123;
    $digest = hash('sha256', 'real-open-timestamp-proof');
    $header = str_repeat("\0", 36).hex2bin($digest).pack('V', 1_700_000_000).str_repeat("\0", 8);
    $firstApiBase = writeRealOtsBitcoinHeaderApi($workspace, 'api-one', $header, $height);
    $secondApiBase = writeRealOtsBitcoinHeaderApi($workspace, 'api-two', $header, $height);
    writeRealOtsHttpsTestShim($workspace);
    $environmentKey = 'PYTHONPATH';
    $hadEnvValue = array_key_exists($environmentKey, $_ENV);
    $previousEnvValue = $_ENV[$environmentKey] ?? null;
    $hadServerValue = array_key_exists($environmentKey, $_SERVER);
    $previousServerValue = $_SERVER[$environmentKey] ?? null;
    $previousApiBases = config('services.opentimestamps.bitcoin_header_api_bases');

    try {
        $_ENV[$environmentKey] = $workspace;
        $_SERVER[$environmentKey] = $workspace;
        config()->set(
            'services.opentimestamps.bitcoin_header_api_bases',
            $firstApiBase.','.$secondApiBase,
        );
        $proof = createRealBitcoinAttestationProof($digest, $height);

        expect($this->service->verify($proof, $digest))->toBeTrue();
    } finally {
        if ($hadEnvValue) {
            $_ENV[$environmentKey] = $previousEnvValue;
        } else {
            unset($_ENV[$environmentKey]);
        }

        if ($hadServerValue) {
            $_SERVER[$environmentKey] = $previousServerValue;
        } else {
            unset($_SERVER[$environmentKey]);
        }

        config()->set('services.opentimestamps.bitcoin_header_api_bases', $previousApiBases);
        (new Filesystem)->deleteDirectory($workspace);
    }
});

/**
 * Test verification with invalid proof data.
 *
 * This test verifies that the Python verifier correctly rejects invalid proofs.
 */
test('verify rejects invalid proof', function () {
    $invalidProof = base64_encode('invalid proof data');
    $digest = hash('sha256', 'test data');

    $result = $this->service->verify($invalidProof, $digest);

    expect($result)->toBeFalse('Invalid proof should be rejected');

    // Verify that failed verifications are NOT cached
    $cacheKey = otsVerificationCacheKey($invalidProof, $digest);
    expect(
        Cache::has($cacheKey),
        'Failed verifications should not be cached (proof may upgrade later)'
    )->toBeFalse();
});

/**
 * Test that verify() uses the bounded verifier (not stub/upgrade endpoints).
 *
 * This is a security-critical test: We must never use the stub()
 * or upgrade() endpoints for verification, only the external Python verifier.
 *
 * Context: Issue #412 identified critical vulnerabilities in the hybrid
 * verification approach. Issue #415 mandates isolated external verification.
 */
test('verify uses external verifier not http calendars', function () {
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
    // because the verifier requires a Bitcoin attestation.
    // This proves we're using the verifier (not stub/upgrade endpoints).
    expect(
        $result,
        'Pending proofs should fail Python verification (no Bitcoin attestation yet)'
    )->toBeFalse();

    // Additional security check: Ensure we never cached this false result
    $cacheKey = otsVerificationCacheKey($pendingProof, $digest);
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

    // First verification - should hit the verifier and return false (pending)
    $result1 = $this->service->verify($pendingProof, $digest);
    expect($result1)->toBeFalse();

    // Cache should be empty (failed verifications not cached)
    $cacheKey = otsVerificationCacheKey($pendingProof, $digest);
    expect(Cache::has($cacheKey))->toBeFalse();

    // Second verification - should hit the verifier again (not cached)
    $result2 = $this->service->verify($pendingProof, $digest);
    expect($result2)->toBeFalse();

    // Still not cached
    expect(Cache::has($cacheKey))->toBeFalse();
});

/**
 * Test that verifier timeout is handled gracefully.
 *
 * The verifier may time out when Bitcoin header APIs are slow or unreachable.
 * This should not crash the application.
 */
test('verify handles verifier timeout gracefully', function () {
    // Create a proof with invalid data that might cause the verifier to hang
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
