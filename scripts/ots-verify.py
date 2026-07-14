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
import http.client
import os
import time
import urllib.request
from urllib.parse import urlsplit, urlunsplit
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
MAX_PROVIDER_REQUEST_SECONDS = 2
MINIMUM_HEADER_API_QUORUM = 2
MAX_PROOF_BYTES = 1024 * 1024
MAX_TIMESTAMP_ATTESTATIONS = 256
MAX_BLOCK_HASH_RESPONSE_BYTES = 128
MAX_BLOCK_HEADER_RESPONSE_BYTES = 256
RECOVERABLE_PROVIDER_ERRORS = (
    http.client.HTTPException,
    OSError,
    ValueError,
)
ATTESTATION_VERIFICATION_ERRORS = RECOVERABLE_PROVIDER_ERRORS + (VerificationError,)

class VerificationDeadlineExceeded(TimeoutError):
    """The shared verification deadline has been exhausted."""

class SameOriginHttpsRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Reject redirect targets before urllib opens a different origin."""

    def __init__(self, expected_origin: str):
        super().__init__()
        self.expected_origin = expected_origin

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        try:
            redirect_origin = canonicalize_api_base(newurl)[1]
        except ValueError as error:
            raise ValueError('Bitcoin header API redirect target must use the original HTTPS origin') from error

        if redirect_origin != self.expected_origin:
            raise ValueError('Bitcoin header API redirected to a different origin')

        return super().redirect_request(req, fp, code, msg, headers, newurl)

def remaining_timeout(deadline: float) -> float:
    """Return the remaining shared verification budget or fail closed."""
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise VerificationDeadlineExceeded('Bitcoin header verification deadline exceeded')

    return remaining

def fair_attempt_deadline(deadline: float, remaining_attempts: int) -> float:
    """Give one provider a bounded share while preserving every later attempt."""
    if remaining_attempts <= 0:
        raise ValueError('Remaining provider attempt count must be positive')

    started_at = time.monotonic()
    return started_at + min(
        MAX_PROVIDER_REQUEST_SECONDS,
        remaining_timeout(deadline) / remaining_attempts,
    )

def canonicalize_api_base(api_base: str):
    """Return a normalized HTTPS API base and its provider origin."""
    parsed = urlsplit(api_base.strip())
    if parsed.scheme.lower() != 'https':
        raise ValueError('Bitcoin header API bases must use HTTPS')
    if not parsed.hostname:
        raise ValueError('Bitcoin header API bases must include a hostname')
    if parsed.username is not None or parsed.password is not None:
        raise ValueError('Bitcoin header API bases must not include credentials')
    if parsed.query or parsed.fragment:
        raise ValueError('Bitcoin header API bases must not include query strings or fragments')

    hostname = parsed.hostname.encode('idna').decode('ascii').lower()
    formatted_hostname = f'[{hostname}]' if ':' in hostname else hostname
    netloc = formatted_hostname if parsed.port in (None, 443) else f'{formatted_hostname}:{parsed.port}'

    return urlunsplit(('https', netloc, parsed.path.rstrip('/'), '', '')), f'https://{netloc}'

def bitcoin_header_api_bases():
    """Return configured APIs from at least two canonical provider origins."""
    configured = os.environ.get('OTS_BITCOIN_HEADER_API_BASES')
    api_bases = DEFAULT_BITCOIN_HEADER_API_BASES if configured is None else (
        base.strip() for base in configured.split(',') if base.strip()
    )

    distinct_origins = {}
    for api_base in api_bases:
        normalized_base, origin = canonicalize_api_base(api_base)
        distinct_origins.setdefault(origin, normalized_base)

    if len(distinct_origins) < MINIMUM_HEADER_API_QUORUM:
        raise ValueError(
            'OTS_BITCOIN_HEADER_API_BASES must contain at least two distinct HTTPS API origins'
        )

    return tuple(distinct_origins.values())

def fetch_api_text(api_base: str, endpoint: str, deadline: float, maximum_bytes: int) -> str:
    """Fetch a bounded response without accepting cross-origin or TLS-downgrade redirects."""
    expected_origin = canonicalize_api_base(api_base)[1]
    opener = urllib.request.build_opener(SameOriginHttpsRedirectHandler(expected_origin))
    with opener.open(
        f'{api_base}{endpoint}',
        timeout=min(remaining_timeout(deadline), MAX_PROVIDER_REQUEST_SECONDS),
    ) as response:
        if canonicalize_api_base(response.geturl())[1] != expected_origin:
            raise ValueError('Bitcoin header API redirected to a different origin')
        response_bytes = response.read(maximum_bytes + 1)

    if len(response_bytes) > maximum_bytes:
        raise ValueError(f'Bitcoin header API response exceeds {maximum_bytes} bytes')
    return response_bytes.decode('ascii').strip()

def fetch_bitcoin_block_hash(api_base: str, height: int, deadline: float) -> str:
    """Fetch and validate a Bitcoin block hash for a height."""
    block_hash = fetch_api_text(api_base, f'/block-height/{height}', deadline, MAX_BLOCK_HASH_RESPONSE_BYTES).lower()

    if len(block_hash) != 64 or not all(c in '0123456789abcdef' for c in block_hash):
        raise ValueError(f'Invalid Bitcoin block hash returned for height {height}')

    return block_hash

def fetch_valid_bitcoin_header(api_base: str, block_hash: str, height: int, deadline: float):
    """Fetch a bounded raw header and prove that it hashes to the agreed block."""
    header_hex = fetch_api_text(api_base, f'/block/{block_hash}/header', deadline, MAX_BLOCK_HEADER_RESPONSE_BYTES)

    try:
        header = binascii.unhexlify(header_hex)
    except binascii.Error as error:
        raise ValueError(f'Invalid Bitcoin block header hex at height {height}') from error

    if len(header) != 80:
        raise ValueError(
            f'Invalid Bitcoin block header length for height {height}: {len(header)} bytes'
        )

    calculated_block_hash = hashlib.sha256(hashlib.sha256(header).digest()).digest()[::-1].hex()
    if calculated_block_hash != block_hash:
        raise ValueError(f'Bitcoin block header hash does not match block {block_hash}')

    return SimpleNamespace(hashMerkleRoot=header[36:68], nTime=int.from_bytes(header[68:72], 'little'))

def fetch_bitcoin_block_header(height: int, deadline: float):
    """Fetch and parse a Bitcoin block header by height from a block explorer API."""
    api_bases = bitcoin_header_api_bases()
    block_hash_sources = {}
    height_lookup_started_at = time.monotonic()
    height_lookup_deadline = height_lookup_started_at + (remaining_timeout(deadline) / 2)

    for index, api_base in enumerate(api_bases):
        try:
            attempt_deadline = fair_attempt_deadline(
                height_lookup_deadline,
                len(api_bases) - index,
            )
            block_hash = fetch_bitcoin_block_hash(api_base, height, attempt_deadline)
        except VerificationDeadlineExceeded:
            if any(
                len(sources) >= MINIMUM_HEADER_API_QUORUM
                for sources in block_hash_sources.values()
            ):
                break
            raise
        except RECOVERABLE_PROVIDER_ERRORS:
            continue

        block_hash_sources.setdefault(block_hash, []).append(api_base)

    quorum_hashes = [
        block_hash
        for block_hash, sources in block_hash_sources.items()
        if len(sources) >= MINIMUM_HEADER_API_QUORUM
    ]
    if len(quorum_hashes) > 1:
        raise ValueError(f'Bitcoin header APIs returned conflicting quorums at height {height}')
    if not quorum_hashes:
        if len(block_hash_sources) > 1:
            raise ValueError(f'Bitcoin header APIs disagree on block hash at height {height}')
        raise ValueError(f'Bitcoin header APIs did not reach quorum at height {height}')

    agreed_block_hash = quorum_hashes[0]
    agreeing_api_bases = block_hash_sources[agreed_block_hash]

    # Any remaining provider can supply a raw header because its double-SHA
    # hash must still match the block hash established by the provider quorum.
    header_api_bases = agreeing_api_bases + [
        api_base for api_base in api_bases if api_base not in agreeing_api_bases
    ]
    last_header_error = None
    for index, api_base in enumerate(header_api_bases):

        try:
            attempt_deadline = fair_attempt_deadline(
                deadline,
                len(header_api_bases) - index,
            )
            return fetch_valid_bitcoin_header(
                api_base,
                agreed_block_hash,
                height,
                attempt_deadline,
            )
        except VerificationDeadlineExceeded:
            raise
        except RECOVERABLE_PROVIDER_ERRORS as error:
            last_header_error = error

    raise ValueError(
        f'No configured API returned a valid Bitcoin block header at height {height}: {last_header_error}'
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
        if len(proof_bytes) > MAX_PROOF_BYTES:
            print(f"Error: Proof exceeds maximum size of {MAX_PROOF_BYTES} bytes", file=sys.stderr)
            return False

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
        bitcoin_attestations = []
        attestation_count = 0
        for msg, att in timestamp.all_attestations():
            attestation_count += 1
            if attestation_count > MAX_TIMESTAMP_ATTESTATIONS:
                raise ValueError(
                    f'Proof exceeds maximum attestation count of {MAX_TIMESTAMP_ATTESTATIONS}'
                )

            print(f"  - {type(att).__name__}", file=sys.stderr)
            if isinstance(att, BitcoinBlockHeaderAttestation):
                bitcoin_attestations.append((msg, att))

        print(f"Total attestations found: {attestation_count}", file=sys.stderr)

        if not bitcoin_attestations:
            print("✗ Proof has no Bitcoin attestations (still pending)", file=sys.stderr)
            return False

        print(f"✓ Found {len(bitcoin_attestations)} Bitcoin attestation(s)", file=sys.stderr)

        # Validate each Bitcoin attestation against the real block header.
        for index, (msg, attestation) in enumerate(bitcoin_attestations):
            block_height = attestation.height
            print(f"  - Bitcoin block: {block_height}", file=sys.stderr)

            try:
                attestation_started_at = time.monotonic()
                remaining_verification_seconds = verification_deadline - attestation_started_at
                if remaining_verification_seconds <= 0:
                    raise VerificationDeadlineExceeded(
                        'Bitcoin header verification deadline exceeded'
                    )

                remaining_attestation_count = len(bitcoin_attestations) - index
                attestation_deadline = attestation_started_at + (
                    remaining_verification_seconds / remaining_attestation_count
                )
                block_header = fetch_bitcoin_block_header(block_height, attestation_deadline)
                attested_time = attestation.verify_against_blockheader(msg, block_header)
            except ATTESTATION_VERIFICATION_ERRORS as e:
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
        with proof_file.open('rb') as proof_stream:
            proof_bytes = proof_stream.read(MAX_PROOF_BYTES + 1)
        if len(proof_bytes) > MAX_PROOF_BYTES:
            print(f"Error: Proof exceeds maximum size of {MAX_PROOF_BYTES} bytes", file=sys.stderr)
            sys.exit(2)
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
