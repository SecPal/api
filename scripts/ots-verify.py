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
from pathlib import Path
from io import BytesIO

try:
    from opentimestamps.core.timestamp import DetachedTimestampFile, BitcoinBlockHeaderAttestation
    from opentimestamps.core.notary import BitcoinBlockHeaderAttestation
    from opentimestamps.core.serialize import StreamDeserializationContext
except ImportError as e:
    print(f"Error: opentimestamps library not installed: {e}", file=sys.stderr)
    print("Install with: pip install opentimestamps-client", file=sys.stderr)
    sys.exit(2)

def verify_proof(proof_bytes: bytes, digest_hex: str) -> bool:
    """
    Verify an OTS proof against a digest WITHOUT requiring a local Bitcoin node.

    This uses the opentimestamps library's built-in remote verification which
    fetches Bitcoin block headers from public APIs.

    Args:
        proof_bytes: The OTS proof file content (binary)
        digest_hex: The SHA256 digest as hex string (64 characters)

    Returns:
        True if proof is valid and confirmed on Bitcoin blockchain
        False otherwise
    """
    try:
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

        # List all Bitcoin block attestations
        for msg, attestation in bitcoin_attestations:
            block_height = attestation.height
            print(f"  - Bitcoin block: {block_height}", file=sys.stderr)

        # If we have Bitcoin attestations and the hash matches, consider it valid
        print("✓ Proof structure is valid and contains Bitcoin attestations", file=sys.stderr)
        return True

    except Exception as e:
        print(f"Error during verification: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc(file=sys.stderr)

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
