#!/usr/bin/env python3
"""
OpenTimestamp proof verification script that works WITHOUT a local Bitcoin node.

This script uses the opentimestamps Python library to verify proofs by fetching
Bitcoin block headers from public APIs instead of requiring a local Bitcoin Core node.

Usage: python3 ots-verify.py <proof_file> <digest_hex>

Exit codes:
  0 - Verification successful (proof is valid)
  1 - Verification failed (proof is invalid or not yet confirmed)
  2 - Error (missing arguments, file not found, etc.)
"""

import sys
import binascii
import hashlib
import os
import time
import urllib.error
import urllib.request
from pathlib import Path
from io import BytesIO
from types import SimpleNamespace

try:
    from opentimestamps.core.timestamp import DetachedTimestampFile
    from opentimestamps.core.notary import BitcoinBlockHeaderAttestation, VerificationError
    from opentimestamps.core.serialize import StreamDeserializationContext
except ImportError as e:
    print("Error: Failed to import the 'opentimestamps' library or required submodules.", file=sys.stderr)
    print(f"Details: {e}", file=sys.stderr)
    print("A common fix is to install or update it with: pip install --upgrade opentimestamps-client", file=sys.stderr)
    sys.exit(2)

DEFAULT_BITCOIN_HEADER_API_BASES = (
    'https://blockstream.info/api',
    'https://mempool.space/api',
)
VERIFICATION_TIMEOUT_SECONDS = 8

def remaining_timeout(deadline: float) -> float:
    """Return the remaining shared verification budget or fail closed."""
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise TimeoutError('Bitcoin header verification deadline exceeded')

    return remaining

def bitcoin_header_api_bases():
    """Return explicitly configured APIs or independent public defaults."""
    configured = os.environ.get('OTS_BITCOIN_HEADER_API_BASES')
    if configured is None:
        api_bases = DEFAULT_BITCOIN_HEADER_API_BASES
    else:
        api_bases = tuple(base.strip().rstrip('/') for base in configured.split(',') if base.strip())

    distinct_api_bases = tuple(dict.fromkeys(api_bases))
    if len(distinct_api_bases) < 2:
        raise ValueError('OTS_BITCOIN_HEADER_API_BASES must contain at least two distinct API base URLs')

    return distinct_api_bases

def fetch_bitcoin_block_header(height: int, deadline: float):
    """Fetch and parse a Bitcoin block header by height from a block explorer API."""
    api_bases = bitcoin_header_api_bases()
    block_hashes = []

    for api_base in api_bases:
        with urllib.request.urlopen(
            f'{api_base}/block-height/{height}',
            timeout=remaining_timeout(deadline),
        ) as response:
            block_hash = response.read().decode('ascii').strip().lower()

        if len(block_hash) != 64 or not all(c in '0123456789abcdef' for c in block_hash):
            raise ValueError(f'Invalid Bitcoin block hash returned for height {height}')

        block_hashes.append(block_hash)

    if len(set(block_hashes)) != 1:
        raise ValueError(f'Bitcoin header APIs disagree on block hash at height {height}')

    block_hash = block_hashes[0]
    with urllib.request.urlopen(
        f'{api_bases[0]}/block/{block_hash}/header',
        timeout=remaining_timeout(deadline),
    ) as response:
        header_hex = response.read().decode('ascii').strip()

    header = binascii.unhexlify(header_hex)
    if len(header) != 80:
        raise ValueError(f'Invalid Bitcoin block header length for height {height}: {len(header)} bytes')

    calculated_block_hash = hashlib.sha256(hashlib.sha256(header).digest()).digest()[::-1].hex()
    if calculated_block_hash != block_hash:
        raise ValueError(f'Bitcoin block header hash does not match block {block_hash}')

    return SimpleNamespace(
        hashMerkleRoot=header[36:68],
        nTime=int.from_bytes(header[68:72], 'little'),
    )

def verify_proof(proof_bytes: bytes, digest_hex: str) -> bool:
    """
    Verify an OTS proof against a digest WITHOUT requiring a local Bitcoin node.

    This validates Bitcoin attestations by fetching the attested block header
    and checking the OTS commitment against that header's Merkle root.

    Args:
        proof_bytes: The OTS proof file content (binary)
        digest_hex: The SHA256 digest as hex string (64 characters)

    Returns:
        True if proof is valid and confirmed on Bitcoin blockchain
        False otherwise
    """
    try:
        verification_deadline = time.monotonic() + VERIFICATION_TIMEOUT_SECONDS

        # Convert hex digest to bytes
        digest = binascii.unhexlify(digest_hex)

        # Deserialize the detached timestamp file
        fd = BytesIO(proof_bytes)
        ctx = StreamDeserializationContext(fd)
        detached_timestamp = DetachedTimestampFile.deserialize(ctx)

        # Verify the digest matches
        if detached_timestamp.file_digest != digest:
            print(f"Error: Digest mismatch!", file=sys.stderr)
            print(f"  Expected: {digest_hex}", file=sys.stderr)
            print(f"  In proof: {binascii.hexlify(detached_timestamp.file_digest).decode()}", file=sys.stderr)
            return False

        print(f"Proof file sha256 hash: {digest_hex}", file=sys.stderr)

        # Check for Bitcoin attestations
        timestamp = detached_timestamp.timestamp
        attestations = list(timestamp.all_attestations())

        print(f"Total attestations found: {len(attestations)}", file=sys.stderr)

        # all_attestations() returns tuples of (msg, attestation)
        bitcoin_attestations = []
        for msg, att in attestations:
            print(f"  - {type(att).__name__}", file=sys.stderr)
            if isinstance(att, BitcoinBlockHeaderAttestation):
                bitcoin_attestations.append((msg, att))

        if not bitcoin_attestations:
            print("✗ Proof has no Bitcoin attestations (still pending)", file=sys.stderr)
            return False

        print(f"✓ Found {len(bitcoin_attestations)} Bitcoin attestation(s)", file=sys.stderr)

        # Validate each Bitcoin attestation against the real block header.
        for msg, attestation in bitcoin_attestations:
            block_height = attestation.height
            print(f"  - Bitcoin block: {block_height}", file=sys.stderr)

            try:
                block_header = fetch_bitcoin_block_header(block_height, verification_deadline)
                attested_time = attestation.verify_against_blockheader(msg, block_header)
            except (urllib.error.URLError, TimeoutError, ValueError, VerificationError) as e:
                print(f"✗ Bitcoin verification failed for block {block_height}: {e}", file=sys.stderr)
                continue

            print(
                f"✓ Bitcoin block {block_height} attests existence as of "
                f"{time.strftime('%Y-%m-%d %Z', time.localtime(attested_time))}",
                file=sys.stderr,
            )
            return True

        print("✗ No Bitcoin attestations matched the corresponding block header", file=sys.stderr)
        return False

    except Exception as e:
        print(f"Error during verification: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc(file=sys.stderr)
        return False

def main():
    if len(sys.argv) != 3:
        print("Usage: ots-verify.py <proof_file> <digest_hex>", file=sys.stderr)
        print("", file=sys.stderr)
        print("Verifies an OpenTimestamp proof against a SHA256 digest", file=sys.stderr)
        print("WITHOUT requiring a local Bitcoin node.", file=sys.stderr)
        sys.exit(2)

    proof_file = Path(sys.argv[1])
    digest_hex = sys.argv[2].lower()

    # Validate inputs
    if not proof_file.exists():
        print(f"Error: Proof file not found: {proof_file}", file=sys.stderr)
        sys.exit(2)

    if len(digest_hex) != 64 or not all(c in '0123456789abcdef' for c in digest_hex):
        print(f"Error: Digest must be 64-character hex string", file=sys.stderr)
        sys.exit(2)

    # Read proof file
    try:
        proof_bytes = proof_file.read_bytes()
    except Exception as e:
        print(f"Error reading proof file: {e}", file=sys.stderr)
        sys.exit(2)

    # Verify
    print(f"Verifying proof for digest: {digest_hex}", file=sys.stderr)
    print("", file=sys.stderr)

    if verify_proof(proof_bytes, digest_hex):
        print("", file=sys.stderr)
        print("SUCCESS: Proof is valid and confirmed on Bitcoin blockchain", file=sys.stderr)
        sys.exit(0)
    else:
        print("", file=sys.stderr)
        print("FAILURE: Proof verification failed", file=sys.stderr)
        sys.exit(1)

if __name__ == '__main__':
    main()
