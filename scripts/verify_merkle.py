#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal
# SPDX-License-Identifier: MIT

"""Minimal Merkle proof verifier helper for activity-log evidence workflows.

This script is intentionally small and dependency-free so legal/audit teams can run
it locally without extra setup.
"""

from __future__ import annotations

import argparse
import hashlib


def sha256_hex(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def verify_merkle_proof(leaf_hash: str, merkle_proof: list[str], merkle_root: str) -> bool:
    current_hash = leaf_hash

    for sibling_hash in merkle_proof:
        combined = current_hash + sibling_hash
        current_hash = sha256_hex(combined)

    return current_hash == merkle_root


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Verify Merkle proof for an activity log hash.")
    parser.add_argument("leaf_hash", help="Hex SHA-256 hash of the leaf event")
    parser.add_argument("merkle_root", help="Expected Merkle root")
    parser.add_argument(
        "proof",
        nargs="*",
        default=[],
        help="Ordered list of sibling hashes in the Merkle proof",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    ok = verify_merkle_proof(args.leaf_hash, args.proof, args.merkle_root)

    if ok:
        print("Merkle proof valid")
        return 0

    print("Merkle proof invalid")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
