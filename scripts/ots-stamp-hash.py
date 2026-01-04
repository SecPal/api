#!/usr/bin/env python3
"""
OTS wrapper for SecPal - stamps a pre-computed SHA256 hash

This script accepts a SHA256 hash and creates an OpenTimestamps proof
WITHOUT hashing the input again. This is crucial because we're timestamping
a Merkle root that is already a SHA256 hash.

Usage: python3 ots-stamp-hash.py <hex_hash>
Output: Binary OTS proof on stdout

The difference from `ots stamp -`:
- `ots stamp -` reads data, computes SHA256(data), and timestamps that
- This script takes a hash directly as the commitment, without re-hashing

Example:
  python3 ots-stamp-hash.py b5026d1bbfbf661feabf878c0c0579c428cb5247811f1529b65bada347ef9ad8
"""

import sys
import binascii
from opentimestamps.core.timestamp import Timestamp, DetachedTimestampFile
from opentimestamps.core.op import OpSHA256
from opentimestamps.core.serialize import StreamSerializationContext
import opentimestamps.calendar

def main():
    if len(sys.argv) != 2:
        print("Usage: ots-stamp-hash.py <hex_hash>", file=sys.stderr)
        sys.exit(1)

    hex_hash = sys.argv[1].strip().lower()

    # Validate hex hash
    if len(hex_hash) != 64:
        print(f"Error: Hash must be 64 hex characters (got {len(hex_hash)})", file=sys.stderr)
        sys.exit(1)

    try:
        digest = binascii.unhexlify(hex_hash)
    except ValueError as e:
        print(f"Error: Invalid hex hash: {e}", file=sys.stderr)
        sys.exit(1)

    # Create timestamp with the digest directly as the message/commitment
    # This is the KEY difference: we use the hash AS-IS, not hash it again
    timestamp = Timestamp(digest)

    # Submit to calendar servers
    # Import DEFAULT_AGGREGATORS from the official opentimestamps library
    # This ensures we always use the current official servers
    try:
        from opentimestamps.cmds import DEFAULT_CALENDAR_URLS
        calendar_urls = list(DEFAULT_CALENDAR_URLS)
    except ImportError:
        # Fallback to hardcoded servers if import fails (older library version)
        calendar_urls = [
            'https://a.pool.opentimestamps.org',
            'https://b.pool.opentimestamps.org',
            'https://a.pool.eternitywall.com',
            'https://ots.btc.catallaxy.com',
        ]

    print(f"Using {len(calendar_urls)} calendar servers", file=sys.stderr)

    submitted_count = 0
    for url in calendar_urls:
        try:
            remote_calendar = opentimestamps.calendar.RemoteCalendar(url)
            # Submit returns a timestamp with calendar attestations
            result = remote_calendar.submit(timestamp.msg)
            timestamp.merge(result)
            submitted_count += 1
            print(f"Submitted to {url}", file=sys.stderr)
        except Exception as e:
            print(f"Warning: Failed to submit to {url}: {e}", file=sys.stderr)
            continue

    if submitted_count == 0:
        print("Error: Failed to submit to any calendar server", file=sys.stderr)
        sys.exit(1)

    # Create DetachedTimestampFile with OpSHA256 as the file hash operation
    # This tells the proof that the "file" being timestamped is identified by SHA256
    detached_timestamp = DetachedTimestampFile(OpSHA256(), timestamp)

    # Serialize to stdout (binary)
    ctx = StreamSerializationContext(sys.stdout.buffer)
    detached_timestamp.serialize(ctx)

    print(f"Success: Created proof with {submitted_count} calendar attestations", file=sys.stderr)

if __name__ == '__main__':
    main()
