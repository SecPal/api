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
 def __init__(self, msg): self.msg = msg
 def all_attestations(self): return [(self.msg, BitcoinBlockHeaderAttestation(123))]
class DetachedTimestampFile:
 @classmethod
 def deserialize(cls, ctx):
  data=ctx.fd.read(); obj=cls(); obj.file_digest=bytes.fromhex(data[:64].decode()); obj.timestamp=Timestamp(bytes.fromhex(data[64:128].decode())); return obj
PY);
    $shim = str_replace('__ROOT__', var_export($workspace, true), <<<'PY'
import os, urllib.request
from urllib.parse import urlsplit
root=__ROOT__; original=urllib.request.urlopen
class Response:
 def __init__(self, response, url): self.response=response; self.url=url
 def __enter__(self): return self
 def __exit__(self, *args): self.response.close()
 def read(self, size=-1):
  if size < 0: raise RuntimeError('response must be bounded')
  return self.response.read(size)
 def geturl(self): return self.url
def urlopen(url, timeout=None):
 parsed=urlsplit(url)
 if parsed.hostname and parsed.hostname.endswith('.test'):
  path=os.path.join(root, parsed.hostname.removesuffix('.test'), parsed.path.removeprefix('/api/'))
  return Response(original('file://'+path, timeout=timeout), url)
 return original(url, timeout=timeout)
urllib.request.urlopen=urlopen
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
function runPythonVerifier(string $workspace, string $apis, string $digest, string $message): array
{
    $proof = $workspace.'/proof.ots';
    file_put_contents($proof, $digest.$message);
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
        [$insecure, $insecureOutput] = runPythonVerifier($workspace, 'http://one.test/api,http://two.test/api', $digest, $message);
        [$unavailable] = runPythonVerifier($workspace, "https://missing.test/api,$one,$two", $digest, $message);
        $three = writeVerifierApi($workspace, 'three', $header);
        $hash = trim(file_get_contents($workspace.'/one/block-height/123'));
        unlink($workspace.'/one/block/'.$hash.'/header');
        [$fallback] = runPythonVerifier($workspace, "$one,$two", $digest, $message);
        unlink($workspace.'/two/block/'.$hash.'/header');
        [$lateFallback] = runPythonVerifier($workspace, "$one,$two,$three", $digest, $message);
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

        expect($valid)->toBe(0)->and($unavailable)->toBe(0)
            ->and($single)->toBe(1)->and($singleOutput)->toContain('two distinct HTTPS API origins')
            ->and($duplicate)->toBe(1)
            ->and($insecure)->toBe(1)->and($insecureOutput)->toContain('must use HTTPS')
            ->and($fallback)->toBe(0)->and($lateFallback)->toBe(0)
            ->and($malformed)->toBe(1)->and($malformedOutput)->toContain('Bitcoin verification failed')->not->toContain('Traceback')
            ->and($merkle)->toBe(1)->and($hash)->toBe(1)
            ->and($disagreement)->toBe(1)->and($disagreementOutput)->toContain('disagree on block hash');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});
