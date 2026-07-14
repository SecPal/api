<?php

/**
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

uses()->group('unit', 'services', 'opentimestamp', 'verification');

function createPythonVerifierWorkspace(): string
{
    $workspace = sys_get_temp_dir().'/ots-python-verifier-'.bin2hex(random_bytes(8));
    mkdir($workspace.'/opentimestamps/core', 0770, true);
    $siteCustomize = str_replace('__WORKSPACE__', var_export($workspace, true), <<<'PY'
import os
import urllib.request
from urllib.parse import urlsplit

workspace = __WORKSPACE__
original_urlopen = urllib.request.urlopen

class BoundedResponse:
    def __init__(self, response, original_url):
        self.response = response
        self.original_url = original_url

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.response.close()

    def read(self, size=-1):
        if size < 0:
            raise RuntimeError('test response must be read with an explicit bound')
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
        return BoundedResponse(response, url)

    return original_urlopen(url, timeout=timeout)

urllib.request.urlopen = local_urlopen
PY);
    file_put_contents($workspace.'/sitecustomize.py', $siteCustomize);
    file_put_contents($workspace.'/opentimestamps/__init__.py', '');
    file_put_contents($workspace.'/opentimestamps/core/__init__.py', '');
    file_put_contents($workspace.'/opentimestamps/core/serialize.py', <<<'PY'
class StreamDeserializationContext:
    def __init__(self, fd):
        self.fd = fd
PY);
    file_put_contents($workspace.'/opentimestamps/core/notary.py', <<<'PY'
class VerificationError(Exception):
    pass

class BitcoinBlockHeaderAttestation:
    def __init__(self, height):
        self.height = height

    def verify_against_blockheader(self, digest, block_header):
        if digest != block_header.hashMerkleRoot:
            raise VerificationError('Digest does not match merkleroot')
        return block_header.nTime
PY);
    file_put_contents($workspace.'/opentimestamps/core/timestamp.py', <<<'PY'
from opentimestamps.core.notary import BitcoinBlockHeaderAttestation

class FakeTimestamp:
    def __init__(self, msg):
        self.msg = msg

    def all_attestations(self):
        return [(self.msg, BitcoinBlockHeaderAttestation(123))]

class DetachedTimestampFile:
    @classmethod
    def deserialize(cls, ctx):
        data = ctx.fd.read()
        obj = cls()
        obj.file_digest = bytes.fromhex(data[:64].decode())
        obj.timestamp = FakeTimestamp(bytes.fromhex(data[64:128].decode()))
        return obj
PY);

    return $workspace;
}

function writeBitcoinHeaderApi(
    string $workspace,
    string $header,
    ?string $reportedBlockHash = null,
    string $directory = 'api',
): string {
    $blockHash = $reportedBlockHash
        ?? bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));
    $api = $workspace.'/'.$directory;

    mkdir($api.'/block-height', 0770, true);
    mkdir($api.'/block/'.$blockHash, 0770, true);
    file_put_contents($api.'/block-height/123', $blockHash);
    file_put_contents($api.'/block/'.$blockHash.'/header', bin2hex($header));

    return 'https://'.$directory.'.test/api';
}

function writeAgreeingBitcoinHeaderApis(
    string $workspace,
    string $header,
    ?string $reportedBlockHash = null,
): string {
    $firstApiBase = writeBitcoinHeaderApi($workspace, $header, $reportedBlockHash, 'api-one');
    $secondApiBase = writeBitcoinHeaderApi($workspace, $header, $reportedBlockHash, 'api-two');

    return $firstApiBase.','.$secondApiBase;
}

function runPythonVerifier(string $workspace, string $apiBase, string $digest, string $attestedMessage): array
{
    $proofFile = $workspace.'/proof.ots';
    file_put_contents($proofFile, $digest.$attestedMessage);

    $command = sprintf(
        'PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=%s OTS_BITCOIN_HEADER_API_BASES=%s python3 %s %s %s 2>&1',
        escapeshellarg($workspace),
        escapeshellarg($apiBase),
        escapeshellarg(base_path('scripts/ots-verify.py')),
        escapeshellarg($proofFile),
        escapeshellarg($digest),
    );

    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

test('python verifier rejects bitcoin attestation when block merkle root does not match attested message', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('11', 32);
        $attestedMessage = implode('', array_map(
            static fn (int $byte): string => sprintf('%02x', $byte),
            range(0, 31),
        ));
        $wrongMerkleRoot = hex2bin(implode('', array_map(
            static fn (int $byte): string => sprintf('%02x', $byte),
            range(32, 63),
        )));
        $header = str_repeat("\0", 36).$wrongMerkleRoot.str_repeat("\0", 12);
        $apiBase = writeAgreeingBitcoinHeaderApis($workspace, $header);

        [$exitCode, $output] = runPythonVerifier($workspace, $apiBase, $digest, $attestedMessage);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Bitcoin verification failed');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier accepts a matching bitcoin block header with wire-order merkle bytes', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('44', 32);
        $attestedMessage = implode('', array_map(
            static fn (int $byte): string => sprintf('%02x', $byte),
            range(0, 31),
        ));
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).pack('V', 1_700_000_000).str_repeat("\0", 8);
        $apiBase = writeAgreeingBitcoinHeaderApis($workspace, $header);

        [$exitCode, $output] = runPythonVerifier($workspace, $apiBase, $digest, $attestedMessage);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier requires at least two distinct bitcoin header APIs', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('45', 32);
        $attestedMessage = str_repeat('46', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).str_repeat("\0", 12);
        $apiBase = writeBitcoinHeaderApi($workspace, $header);

        [$exitCode, $output] = runPythonVerifier($workspace, $apiBase, $digest, $attestedMessage);
        [$duplicateExitCode, $duplicateOutput] = runPythonVerifier(
            $workspace,
            $apiBase.','.$apiBase,
            $digest,
            $attestedMessage,
        );

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('at least two distinct HTTPS API origins')
            ->and($duplicateExitCode)->toBe(1)
            ->and($duplicateOutput)->toContain('at least two distinct HTTPS API origins');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects equivalent spellings of the same bitcoin header API origin', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('47', 32);
        $attestedMessage = str_repeat('48', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).str_repeat("\0", 12);
        writeBitcoinHeaderApi($workspace, $header, directory: 'api-one');

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            'https://API-ONE.test:443/api/,https://api-one.test/api',
            $digest,
            $attestedMessage,
        );

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('at least two distinct HTTPS API origins');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects non https bitcoin header APIs', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('49', 32);
        $attestedMessage = str_repeat('4a', 32);

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            'http://api-one.test/api,http://api-two.test/api',
            $digest,
            $attestedMessage,
        );

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('must use HTTPS');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier accepts a two source quorum when an additional API is unavailable', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('4b', 32);
        $attestedMessage = str_repeat('4c', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).pack('V', 1_700_000_000).str_repeat("\0", 8);
        $agreeingApiBases = writeAgreeingBitcoinHeaderApis($workspace, $header);

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            'https://api-unavailable.test/api,'.$agreeingApiBases,
            $digest,
            $attestedMessage,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier fetches a header from another agreeing API when the first header endpoint fails', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('4d', 32);
        $attestedMessage = str_repeat('4e', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).pack('V', 1_700_000_000).str_repeat("\0", 8);
        $apiBases = writeAgreeingBitcoinHeaderApis($workspace, $header);
        $blockHash = bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));
        unlink($workspace.'/api-one/block/'.$blockHash.'/header');

        [$exitCode, $output] = runPythonVerifier($workspace, $apiBases, $digest, $attestedMessage);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier discovers an additional agreeing API when quorum header endpoints fail', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('51', 32);
        $attestedMessage = str_repeat('52', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).pack('V', 1_700_000_000).str_repeat("\0", 8);
        $firstApiBase = writeBitcoinHeaderApi($workspace, $header, directory: 'api-one');
        $secondApiBase = writeBitcoinHeaderApi($workspace, $header, directory: 'api-two');
        $thirdApiBase = writeBitcoinHeaderApi($workspace, $header, directory: 'api-three');
        $blockHash = bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));
        unlink($workspace.'/api-one/block/'.$blockHash.'/header');
        unlink($workspace.'/api-two/block/'.$blockHash.'/header');

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            $firstApiBase.','.$secondApiBase.','.$thirdApiBase,
            $digest,
            $attestedMessage,
        );

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Proof is valid and confirmed on Bitcoin blockchain');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier reports malformed header hex without a traceback', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('4f', 32);
        $attestedMessage = str_repeat('50', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).str_repeat("\0", 12);
        $apiBases = writeAgreeingBitcoinHeaderApis($workspace, $header);
        $blockHash = bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));
        file_put_contents($workspace.'/api-one/block/'.$blockHash.'/header', 'not-hex');
        file_put_contents($workspace.'/api-two/block/'.$blockHash.'/header', 'not-hex');

        [$exitCode, $output] = runPythonVerifier($workspace, $apiBases, $digest, $attestedMessage);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Bitcoin verification failed')
            ->and($output)->not->toContain('Traceback');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects a header that does not match the reported bitcoin block hash', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('55', 32);
        $attestedMessage = str_repeat('66', 32);
        $header = str_repeat("\0", 36).hex2bin($attestedMessage).str_repeat("\0", 12);
        $apiBase = writeAgreeingBitcoinHeaderApis($workspace, $header, str_repeat('00', 32));

        [$exitCode, $output] = runPythonVerifier($workspace, $apiBase, $digest, $attestedMessage);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('block header hash');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier rejects bitcoin header APIs that disagree on the attested block', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $digest = str_repeat('77', 32);
        $attestedMessage = str_repeat('88', 32);
        $firstHeader = str_repeat("\0", 36).hex2bin($attestedMessage).str_repeat("\0", 12);
        $secondHeader = str_repeat("\1", 36).hex2bin($attestedMessage).str_repeat("\1", 12);
        $firstApiBase = writeBitcoinHeaderApi($workspace, $firstHeader, directory: 'api-one');
        $secondApiBase = writeBitcoinHeaderApi($workspace, $secondHeader, directory: 'api-two');

        [$exitCode, $output] = runPythonVerifier(
            $workspace,
            $firstApiBase.','.$secondApiBase,
            $digest,
            $attestedMessage,
        );

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('APIs disagree on block hash');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});

test('python verifier stops fetching when the shared verification deadline has expired', function () {
    $workspace = createPythonVerifierWorkspace();

    try {
        $python = <<<'PY'
import importlib.util
import sys

spec = importlib.util.spec_from_file_location('ots_verify', sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
module.fetch_bitcoin_block_header(123, 0)
PY;
        $command = sprintf(
            'PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=%s python3 -c %s %s 2>&1',
            escapeshellarg($workspace),
            escapeshellarg($python),
            escapeshellarg(base_path('scripts/ots-verify.py')),
        );

        exec($command, $output, $exitCode);

        expect($exitCode)->toBe(1)
            ->and(implode("\n", $output))->toContain('Bitcoin header verification deadline exceeded');
    } finally {
        (new Filesystem)->deleteDirectory($workspace);
    }
});
