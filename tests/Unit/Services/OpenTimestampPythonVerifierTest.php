<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

function pythonVerifierWorkspace(): string
{
    $workspace = sys_get_temp_dir().'/ots-verifier-'.bin2hex(random_bytes(8));
    mkdir($workspace.'/opentimestamps/core', 0770, true);
    foreach (['opentimestamps', 'opentimestamps/core'] as $package) {
        file_put_contents($workspace.'/'.$package.'/__init__.py', '');
    }
    file_put_contents($workspace.'/opentimestamps/core/serialize.py', <<<'PY'
class StreamDeserializationContext:
 def __init__(self, fd): self.fd = fd
PY);
    file_put_contents($workspace.'/opentimestamps/core/notary.py', <<<'PY'
class VerificationError(Exception): pass
class BitcoinBlockHeaderAttestation:
 def __init__(self, height): self.height = height
 def verify_against_blockheader(self, digest, header):
  if digest != header.hashMerkleRoot: raise VerificationError('Digest does not match merkleroot')
  return header.nTime
PY);
    file_put_contents($workspace.'/opentimestamps/core/timestamp.py', <<<'PY'
from opentimestamps.core.notary import BitcoinBlockHeaderAttestation
class Timestamp:
 def __init__(self, msg, mode): self.msg = msg; self.mode = mode
 def all_attestations(self):
  if self.mode == b'multiple': return [(self.msg, BitcoinBlockHeaderAttestation(111)), (self.msg, BitcoinBlockHeaderAttestation(123))]
  if self.mode == b'many': return [(self.msg, BitcoinBlockHeaderAttestation(123)) for _ in range(257)]
  return [(self.msg, BitcoinBlockHeaderAttestation(123))]
class DetachedTimestampFile:
 @classmethod
 def deserialize(cls, ctx):
  data=ctx.fd.read(); obj=cls(); obj.file_digest=bytes.fromhex(data[:64].decode()); obj.timestamp=Timestamp(bytes.fromhex(data[64:128].decode()), data[128:]); return obj
PY);
    $shim = str_replace('__ROOT__', var_export($workspace, true), <<<'PY'
import os, time, urllib.request
from http.client import IncompleteRead
from urllib.parse import urlsplit
root=__ROOT__; original=urllib.request.urlopen; elapsed=0; original_monotonic=time.monotonic
def monotonic(): return original_monotonic()+elapsed
time.monotonic=monotonic
class Response:
 def __init__(self, response, url): self.response=response; self.url=url
 def __enter__(self): return self
 def __exit__(self, *args): self.response.close()
 def read(self, size=-1):
  if size < 0: raise RuntimeError('response must be bounded')
  parsed=urlsplit(self.url)
  if parsed.hostname == 'truncated-height.test' and '/block-height/' in parsed.path: raise IncompleteRead(b'', 1)
  if parsed.hostname == 'truncated-header.test' and parsed.path.endswith('/header'): raise IncompleteRead(b'', 1)
  return self.response.read(size)
 def geturl(self): return self.url
def urlopen(url, timeout=None):
 global elapsed
 parsed=urlsplit(url)
 if parsed.hostname == 'redirect.test':
  print('UNSAFE_REDIRECT_TARGET_CONTACTED')
  raise OSError('redirect target was contacted before validation')
 if parsed.hostname and parsed.hostname.startswith('attestation-slow-') and '/block-height/111' in parsed.path:
  elapsed += timeout
  raise TimeoutError('first attestation consumed its provider budget')
 if parsed.hostname and parsed.hostname.startswith('height-slow-') and '/block-height/' in parsed.path:
  elapsed += timeout
  raise TimeoutError('height provider consumed its fair request budget')
 if parsed.hostname and parsed.hostname.startswith('budget-slow-'):
  elapsed += timeout
  raise TimeoutError('provider consumed its request budget')
 if parsed.hostname and parsed.hostname.startswith('slow-header') and parsed.path.endswith('/header'):
  elapsed += timeout
  raise TimeoutError('header provider consumed its request budget')
 if parsed.hostname == 'slow.test':
  if timeout > 2: raise RuntimeError('provider received the shared deadline')
  raise TimeoutError('provider timed out')
 if parsed.hostname and parsed.hostname.endswith('.test'):
  path=os.path.join(root, parsed.hostname.removesuffix('.test'), parsed.path.removeprefix('/api/'))
  return Response(open(path, 'rb'), url)
 return original(url, timeout=timeout)
class Opener:
 def __init__(self, handlers): self.handlers=handlers
 def open(self, url, timeout=None):
  parsed=urlsplit(url)
  if parsed.hostname == 'redirect.test':
   request=urllib.request.Request(url)
   for handler in self.handlers:
    if hasattr(handler, 'redirect_request'):
     handler.redirect_request(request, None, 302, 'Found', {}, 'http://127.0.0.1/internal')
  return urlopen(url, timeout=timeout)
def build_opener(*handlers): return Opener(handlers)
urllib.request.urlopen=urlopen
urllib.request.build_opener=build_opener
PY);
    file_put_contents($workspace.'/sitecustomize.py', $shim);

    return $workspace;
}

function writeVerifierApi(
    string $workspace,
    string $name,
    string $header,
    ?string $reportedHash = null,
    ?string $headerText = null,
): string {
    $hash = $reportedHash ?? bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));
    $root = $workspace.'/'.$name;
    (new Filesystem)->ensureDirectoryExists($root.'/block-height', 0770);
    (new Filesystem)->ensureDirectoryExists($root.'/block/'.$hash, 0770);
    file_put_contents($root.'/block-height/123', $hash);
    file_put_contents($root.'/block/'.$hash.'/header', $headerText ?? bin2hex($header));

    return 'https://'.$name.'.test/api';
}

/** @return array{int, string} */
function runPythonVerifier(
    string $workspace,
    string $apis,
    string $digest,
    string $message,
    string $proofSuffix = '',
): array {
    $proof = $workspace.'/proof.ots';
    file_put_contents($proof, $digest.$message.$proofSuffix);
    $command = sprintf(
        'PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=%s OTS_BITCOIN_HEADER_API_BASES=%s python3 %s %s %s 2>&1',
        escapeshellarg($workspace),
        escapeshellarg($apis),
        escapeshellarg(base_path('scripts/ots-verify.py')),
        escapeshellarg($proof),
        escapeshellarg($digest),
    );
    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

test('python verifier validates headers using independent HTTPS provider consensus', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);
        [$valid] = runPythonVerifier($workspace, "$one,$two", $digest, $message);
        [$single, $singleOutput] = runPythonVerifier($workspace, $one, $digest, $message);
        [$duplicate] = runPythonVerifier($workspace, 'https://ONE.test:443/api/,'.$one, $digest, $message);
        [$trailingDotDuplicate, $trailingDotDuplicateOutput] = runPythonVerifier(
            $workspace,
            'https://one.test./api,'.$one,
            $digest,
            $message,
        );
        [$insecure, $insecureOutput] = runPythonVerifier($workspace, 'http://one.test/api,http://two.test/api', $digest, $message);
        [$unavailable] = runPythonVerifier($workspace, "https://missing.test/api,$one,$two", $digest, $message);
        [$slow] = runPythonVerifier($workspace, "https://slow.test/api,$one,$two", $digest, $message);
        $three = writeVerifierApi($workspace, 'three', $header);
        $hash = trim(file_get_contents($workspace.'/one/block-height/123'));
        unlink($workspace.'/one/block/'.$hash.'/header');
        [$fallback] = runPythonVerifier($workspace, "$one,$two", $digest, $message);
        unlink($workspace.'/two/block/'.$hash.'/header');
        [$lateFallback] = runPythonVerifier($workspace, "$one,$two,$three", $digest, $message);
        $heightFallback = writeVerifierApi($workspace, 'height-fallback', $header);
        $quorumOne = writeVerifierApi($workspace, 'quorum-one', $header);
        $quorumTwo = writeVerifierApi($workspace, 'quorum-two', $header);
        unlink($workspace.'/height-fallback/block-height/123');
        unlink($workspace.'/quorum-one/block/'.$hash.'/header');
        unlink($workspace.'/quorum-two/block/'.$hash.'/header');
        [$heightLookupFallback] = runPythonVerifier($workspace, "$heightFallback,$quorumOne,$quorumTwo", $digest, $message);
        writeVerifierApi($workspace, 'one', $header, headerText: 'not-hex');
        writeVerifierApi($workspace, 'two', $header, headerText: 'not-hex');
        [$malformed, $malformedOutput] = runPythonVerifier($workspace, "$one,$two", $digest, $message);
        $wrongMerkle = str_repeat("\0", 80);
        $wrongOne = writeVerifierApi($workspace, 'wrong-one', $wrongMerkle);
        $wrongTwo = writeVerifierApi($workspace, 'wrong-two', $wrongMerkle);
        [$merkle] = runPythonVerifier($workspace, "$wrongOne,$wrongTwo", $digest, $message);
        $zero = str_repeat('0', 64);
        $hashOne = writeVerifierApi($workspace, 'hash-one', $header, $zero);
        $hashTwo = writeVerifierApi($workspace, 'hash-two', $header, $zero);
        [$hash] = runPythonVerifier($workspace, "$hashOne,$hashTwo", $digest, $message);
        $other = str_repeat("\1", 80);
        $first = writeVerifierApi($workspace, 'first', $header);
        $second = writeVerifierApi($workspace, 'second', $other);
        [$disagreement, $disagreementOutput] = runPythonVerifier($workspace, "$first,$second", $digest, $message);

        expect($valid)->toBe(0)->and($unavailable)->toBe(0)->and($heightLookupFallback)->toBe(0)->and($slow)->toBe(0)
            ->and($single)->toBe(1)->and($singleOutput)->toContain('two distinct HTTPS API origins')
            ->and($duplicate)->toBe(1)
            ->and($trailingDotDuplicate)->toBe(1)
            ->and($trailingDotDuplicateOutput)->toContain('two distinct HTTPS API origins')
            ->and($insecure)->toBe(1)->and($insecureOutput)->toContain('must use HTTPS')
            ->and($fallback)->toBe(0)->and($lateFallback)->toBe(0)
            ->and($malformed)->toBe(1)->and($malformedOutput)->toContain('Bitcoin verification failed')->not->toContain('Traceback')
            ->and($merkle)->toBe(1)->and($hash)->toBe(1)
            ->and($disagreement)->toBe(1)->and($disagreementOutput)->toContain('disagree on block hash');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier recovers from a truncated provider response', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $truncatedHeight = writeVerifierApi($workspace, 'truncated-height', $header);
        $truncatedHeader = writeVerifierApi($workspace, 'truncated-header', $header);
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);

        [$heightExitCode] = runPythonVerifier($workspace, "$truncatedHeight,$one,$two", $digest, $message);
        [$headerExitCode] = runPythonVerifier($workspace, "$truncatedHeader,$one,$two", $digest, $message);

        expect($heightExitCode)->toBe(0)
            ->and($headerExitCode)->toBe(0);
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects conflicting provider quorums', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);
    $conflictingHeader = substr($header, 0, 79)."\1";

    try {
        $first = writeVerifierApi($workspace, 'first', $header);
        $second = writeVerifierApi($workspace, 'second', $header);
        $third = writeVerifierApi($workspace, 'third', $conflictingHeader);
        $fourth = writeVerifierApi($workspace, 'fourth', $conflictingHeader);
        $fifth = writeVerifierApi($workspace, 'fifth', $header);

        [$minorityExitCode] = runPythonVerifier(
            $workspace,
            "$first,$second,$third",
            $digest,
            $message,
        );
        [$splitExitCode, $splitOutput] = runPythonVerifier(
            $workspace,
            "$first,$second,$third,$fourth",
            $digest,
            $message,
        );
        [$majorityExitCode, $majorityOutput] = runPythonVerifier(
            $workspace,
            "$first,$second,$third,$fourth,$fifth",
            $digest,
            $message,
        );

        expect($minorityExitCode)->toBe(0)
            ->and($splitExitCode)->toBe(1)
            ->and($splitOutput)->toContain('conflicting quorums')
            ->and($majorityExitCode)->toBe(1)
            ->and($majorityOutput)->toContain('conflicting quorums');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier preserves header budget after reaching provider quorum', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);
        $slowProviders = implode(',', array_map(
            static fn (int $provider): string => "https://budget-slow-{$provider}.test/api",
            range(1, 4),
        ));

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            "$one,$two,$slowProviders",
            $digest,
            $message,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('SUCCESS: Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier preserves header fallback budget after one provider times out', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $slowHeader = writeVerifierApi($workspace, 'slow-header', $header);
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);
        $slowProviders = implode(',', array_map(
            static fn (int $provider): string => "https://budget-slow-{$provider}.test/api",
            range(1, 3),
        ));

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            "$slowHeader,$one,$two,$slowProviders",
            $digest,
            $message,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('SUCCESS: Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier preserves height quorum budget after two providers time out', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            "https://height-slow-one.test/api,https://height-slow-two.test/api,$one,$two",
            $digest,
            $message,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('SUCCESS: Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier preserves a third header attempt after two providers time out', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $slowOne = writeVerifierApi($workspace, 'slow-header-one', $header);
        $slowTwo = writeVerifierApi($workspace, 'slow-header-two', $header);
        $healthy = writeVerifierApi($workspace, 'healthy', $header);

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            "$slowOne,$slowTwo,$healthy,https://budget-slow-one.test/api,https://budget-slow-two.test/api",
            $digest,
            $message,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('SUCCESS: Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects cross origin redirects before contacting their target', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $redirect = writeVerifierApi($workspace, 'redirect', $header);
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            "$redirect,$one,$two",
            $digest,
            $message,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain('UNSAFE_REDIRECT_TARGET_CONTACTED');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier preserves budget for later bitcoin attestations', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $one = writeVerifierApi($workspace, 'attestation-slow-one', $header);
        $two = writeVerifierApi($workspace, 'attestation-slow-two', $header);

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            "$one,$two",
            $digest,
            $message,
            'multiple',
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Bitcoin block 123 attests existence');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects oversized proofs and excessive attestations', function () {
    $workspace = pythonVerifierWorkspace();
    $digest = str_repeat('11', 32);
    $message = str_repeat('22', 32);
    $header = str_repeat("\0", 36).hex2bin($message).pack('V', 1_700_000_000).str_repeat("\0", 8);

    try {
        $one = writeVerifierApi($workspace, 'one', $header);
        $two = writeVerifierApi($workspace, 'two', $header);
        [$oversizedExitCode, $oversizedOutput] = runPythonVerifier(
            $workspace,
            "$one,$two",
            $digest,
            $message,
            str_repeat('x', 1_048_577 - 128),
        );
        [$attestationsExitCode, $attestationsOutput] = runPythonVerifier(
            $workspace,
            "$one,$two",
            $digest,
            $message,
            'many',
        );

        expect($oversizedExitCode)->toBe(2)
            ->and($oversizedOutput)->toContain('Proof exceeds maximum size')
            ->and($attestationsExitCode)->toBe(1)
            ->and($attestationsOutput)->toContain('Proof exceeds maximum attestation count');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});
