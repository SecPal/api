#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Upgrade one pending OTS proof through approved public calendars."""

from __future__ import annotations

import binascii
import io
import ipaddress
import socket
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urljoin, urlsplit

import opentimestamps.calendar
from opentimestamps.core.notary import BitcoinBlockHeaderAttestation, PendingAttestation
from opentimestamps.core.serialize import (
    DeserializationError,
    StreamDeserializationContext,
    StreamSerializationContext,
)
from opentimestamps.core.timestamp import DetachedTimestampFile, Timestamp


MAX_PROOF_BYTES = 1_048_576
MAX_CALENDAR_REQUESTS = 4
TOTAL_TIMEOUT_SECONDS = 8.0
REQUEST_TIMEOUT_SECONDS = 2.0


class RejectRedirects(urllib.request.HTTPRedirectHandler):
    """Never follow a calendar redirect to a different network target."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise urllib.error.URLError("calendar redirects are not allowed")


def walk_timestamps(timestamp: Timestamp):
    yield timestamp
    for child in timestamp.ops.values():
        yield from walk_timestamps(child)


def attestation_set(timestamp: Timestamp):
    return set(timestamp.all_attestations())


def approved_calendar(uri: str) -> bool:
    try:
        parsed = urlsplit(uri)
        port = parsed.port
    except ValueError:
        return False

    return (
        parsed.scheme == "https"
        and parsed.hostname is not None
        and parsed.username is None
        and parsed.password is None
        and port in (None, 443)
        and not parsed.query
        and not parsed.fragment
        and uri in opentimestamps.calendar.DEFAULT_CALENDAR_WHITELIST
    )


def require_public_address(hostname: str, port: int) -> None:
    addresses = socket.getaddrinfo(hostname, port, type=socket.SOCK_STREAM)
    if not addresses:
        raise urllib.error.URLError("calendar hostname did not resolve")

    for address in addresses:
        ip = ipaddress.ip_address(address[4][0])
        if not ip.is_global:
            raise urllib.error.URLError("calendar resolved to a non-public address")


def calendar_urlopen(expected_url: str):
    original_urlopen = urllib.request.urlopen
    opener = urllib.request.build_opener(RejectRedirects())

    def safe_urlopen(request, timeout=None):
        requested_url = request.full_url if isinstance(request, urllib.request.Request) else str(request)
        if requested_url != expected_url:
            raise urllib.error.URLError("unexpected calendar request path")
        parsed = urlsplit(requested_url)
        require_public_address(parsed.hostname or "", parsed.port or 443)
        return opener.open(request, timeout=timeout)

    return original_urlopen, safe_urlopen


def serialize(detached: DetachedTimestampFile) -> bytes:
    output = io.BytesIO()
    detached.serialize(StreamSerializationContext(output))
    return output.getvalue()


def load_proof(original: bytes) -> DetachedTimestampFile:
    if not original or len(original) > MAX_PROOF_BYTES:
        raise DeserializationError("invalid proof size")
    stream = io.BytesIO(original)
    context = StreamDeserializationContext(stream)
    detached = DetachedTimestampFile.deserialize(context)
    context.assert_eof()
    return detached


def upgrade(detached: DetachedTimestampFile) -> bool:
    started = time.monotonic()
    requests = 0
    bitcoin_added = False
    existing = attestation_set(detached.timestamp)

    pending = []
    for timestamp in walk_timestamps(detached.timestamp):
        for attestation in list(timestamp.attestations):
            if isinstance(attestation, PendingAttestation):
                pending.append((timestamp, attestation.uri))

    for timestamp, uri in pending:
        if requests >= MAX_CALENDAR_REQUESTS:
            break
        if not approved_calendar(uri):
            continue

        remaining = TOTAL_TIMEOUT_SECONDS - (time.monotonic() - started)
        if remaining <= 0:
            break
        timeout = min(REQUEST_TIMEOUT_SECONDS, remaining)
        expected_url = urljoin(uri, "timestamp/" + binascii.hexlify(timestamp.msg).decode("ascii"))
        original_urlopen, safe_urlopen = calendar_urlopen(expected_url)
        requests += 1
        try:
            urllib.request.urlopen = safe_urlopen
            remote = opentimestamps.calendar.RemoteCalendar(uri)
            upgraded = remote.get_timestamp(timestamp.msg, timeout=timeout)
        except (opentimestamps.calendar.CommitmentNotFoundError, OSError, ValueError):
            continue
        finally:
            urllib.request.urlopen = original_urlopen

        remote_attestations = attestation_set(upgraded)
        new_attestations = remote_attestations.difference(existing)
        if not new_attestations:
            continue

        timestamp.merge(upgraded)
        existing.update(new_attestations)
        if any(isinstance(attestation, BitcoinBlockHeaderAttestation) for _, attestation in new_attestations):
            bitcoin_added = True

    return bitcoin_added


def main() -> int:
    if len(sys.argv) != 2:
        print("EXECUTION_ERROR", file=sys.stderr)
        return 2

    path = Path(sys.argv[1])
    try:
        original = path.read_bytes()
        detached = load_proof(original)
    except (OSError, ValueError, DeserializationError):
        print("INVALID_PROOF", file=sys.stderr)
        return 2

    try:
        bitcoin_added = upgrade(detached)
        if not bitcoin_added:
            print("STILL_PENDING", file=sys.stderr)
            return 1

        upgraded = serialize(detached)
        if upgraded == original:
            print("STILL_PENDING", file=sys.stderr)
            return 1

        with path.open("r+b") as proof_file:
            proof_file.write(upgraded)
            proof_file.truncate()
            proof_file.flush()
        print("UPGRADED", file=sys.stderr)
        return 0
    except Exception:
        print("EXECUTION_ERROR", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
