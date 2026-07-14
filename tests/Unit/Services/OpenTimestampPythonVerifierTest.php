<?php

/**
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

uses()->group('unit', 'services', 'opentimestamp', 'verification');

test('python verifier rejects bitcoin attestation when block merkle root does not match attested message', function () {
    $workspace = sys_get_temp_dir().'/ots-python-verifier-'.bin2hex(random_bytes(8));
    mkdir($workspace.'/opentimestamps/core', 0770, true);
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

    $digest = str_repeat('11', 32);
    $attestedMessage = str_repeat('22', 32);
    $wrongMerkleRoot = str_repeat('33', 32);
    $proofFile = $workspace.'/forged.ots';
    file_put_contents($proofFile, $digest.$attestedMessage);

    $api = $workspace.'/api';
    mkdir($api.'/block-height', 0770, true);
    mkdir($api.'/block/0000000000000000000000000000000000000000000000000000000000000000', 0770, true);
    file_put_contents($api.'/block-height/123', '0000000000000000000000000000000000000000000000000000000000000000');
    $headerHex = str_repeat('00', 36).bin2hex(strrev(hex2bin($wrongMerkleRoot))).str_repeat('00', 12);
    file_put_contents($api.'/block/0000000000000000000000000000000000000000000000000000000000000000/header', $headerHex);

    $command = sprintf(
        'PYTHONPATH=%s OTS_BITCOIN_HEADER_API_BASE=%s python3 %s %s %s 2>&1',
        escapeshellarg($workspace),
        escapeshellarg('file://'.$api),
        escapeshellarg(base_path('scripts/ots-verify.py')),
        escapeshellarg($proofFile),
        escapeshellarg($digest),
    );

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $output))->toContain('Bitcoin verification failed');
});
