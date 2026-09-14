#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Upgrade one pending OTS proof through approved public calendars."""

from __future__ import annotations

import binascii
import http.client
import io
import ipaddress
import os
import signal
import socket
import ssl
import sys
import tempfile
import time
import urllib.error
import urllib.request
from contextlib import contextmanager
from pathlib import Path
from urllib.parse import urljoin, urlsplit

try:
    import opentimestamps.calendar
    from opentimestamps.core.notary import BitcoinBlockHeaderAttestation, PendingAttestation
    from opentimestamps.core.serialize import (
        DeserializationError,
        StreamDeserializationContext,
        StreamSerializationContext,
    )
    from opentimestamps.core.timestamp import DetachedTimestampFile, Timestamp
except Exception:
    print("EXECUTION_ERROR", file=sys.stderr)
    raise SystemExit(2)


MAX_PROOF_BYTES = 1_048_576
MAX_CALENDAR_REQUESTS = 4
TOTAL_TIMEOUT_SECONDS = 8.0
REQUEST_TIMEOUT_SECONDS = 2.0


@contextmanager
def wall_clock_timeout(seconds: float):
    """Interrupt all work in this single-purpose process at one deadline."""

    if seconds <= 0:
        raise TimeoutError("calendar operation timed out")

    def deadline_reached(signum, frame):
        raise TimeoutError("calendar operation timed out")

    started = time.monotonic()
    previous_handler = signal.getsignal(signal.SIGALRM)
    signal.signal(signal.SIGALRM, deadline_reached)
    previous_delay, previous_interval = signal.setitimer(signal.ITIMER_REAL, seconds)
    try:
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous_handler)
        if previous_delay > 0:
            elapsed = time.monotonic() - started
            signal.setitimer(
                signal.ITIMER_REAL,
                max(previous_delay - elapsed, 0.000001),
                previous_interval,
            )


class PinnedHTTPSConnection(http.client.HTTPSConnection):
    """Use validated addresses while retaining hostname-verified TLS."""

    def __init__(self, hostname: str, port: int, addresses, timeout: float):
        super().__init__(
            hostname,
            port,
            timeout=timeout,
            context=ssl.create_default_context(),
        )
        self.validated_addresses = addresses

    def connect(self):
        if self._tunnel_host is not None:
            raise OSError("calendar proxies are not supported")

        deadline = time.monotonic() + self.timeout
        last_error = None
        for family, socktype, protocol, address in self.validated_addresses:
            transport = None
            try:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError("calendar connection timed out")
                transport = socket.socket(family, socktype, protocol)
                transport.settimeout(remaining)
                transport.connect(address)
                self.sock = self._context.wrap_socket(
                    transport,
                    server_hostname=self.host,
                )
                return
            except OSError as error:
                last_error = error
                if transport is not None:
                    transport.close()

        raise last_error or OSError("calendar has no validated address")


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


def resolve_public_addresses(hostname: str, port: int):
    addresses = socket.getaddrinfo(hostname, port, type=socket.SOCK_STREAM)
    if not addresses:
        raise urllib.error.URLError("calendar hostname did not resolve")

    validated = []
    for address in addresses:
        if address[0] not in (socket.AF_INET, socket.AF_INET6):
            raise urllib.error.URLError("calendar resolved to an unsupported address")
        ip = ipaddress.ip_address(address[4][0])
        if not ip.is_global:
            raise urllib.error.URLError("calendar resolved to a non-public address")
        candidate = (address[0], address[1], address[2], address[4])
        if candidate not in validated:
            validated.append(candidate)

    return tuple(validated)


def calendar_urlopen(expected_url: str):
    original_urlopen = urllib.request.urlopen

    def safe_urlopen(request, timeout=None):
        requested_url = request.full_url if isinstance(request, urllib.request.Request) else str(request)
        if requested_url != expected_url:
            raise urllib.error.URLError("unexpected calendar request path")
        if not isinstance(request, urllib.request.Request) or request.get_method() != "GET":
            raise urllib.error.URLError("unexpected calendar request method")
        parsed = urlsplit(requested_url)
        hostname = parsed.hostname or ""
        port = parsed.port or 443
        addresses = resolve_public_addresses(hostname, port)
        request_timeout = REQUEST_TIMEOUT_SECONDS if timeout is None else timeout
        connection = PinnedHTTPSConnection(
            hostname,
            port,
            addresses,
            timeout=request_timeout,
        )
        connection.request(
            "GET",
            parsed.path,
            headers=dict(request.header_items()),
        )
        response = connection.getresponse()
        if 300 <= response.status < 400:
            response.close()
            connection.close()
            raise urllib.error.URLError("calendar redirects are not allowed")
        if response.status >= 400:
            raise urllib.error.HTTPError(
                requested_url,
                response.status,
                response.reason,
                response.headers,
                response,
            )
        return response

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


def read_proof_bytes(path: Path) -> bytes:
    with path.open("rb") as proof_file:
        original = proof_file.read(MAX_PROOF_BYTES + 1)
    if len(original) > MAX_PROOF_BYTES:
        raise DeserializationError("invalid proof size")
    return original


def atomic_replace(path: Path, upgraded: bytes) -> None:
    staged_path = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="wb",
            dir=path.parent,
            prefix=f".{path.name}.",
            delete=False,
        ) as staged_file:
            staged_path = Path(staged_file.name)
            if staged_file.write(upgraded) != len(upgraded):
                raise OSError("short proof write")
            staged_file.flush()
            os.fsync(staged_file.fileno())

        os.replace(staged_path, path)
        directory_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        staged_path = None
    finally:
        if staged_path is not None:
            staged_path.unlink(missing_ok=True)


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
            with wall_clock_timeout(timeout):
                urllib.request.urlopen = safe_urlopen
                remote = opentimestamps.calendar.RemoteCalendar(uri)
                upgraded = remote.get_timestamp(timestamp.msg, timeout=timeout)
        except (
            opentimestamps.calendar.CommitmentNotFoundError,
            DeserializationError,
            OSError,
            ValueError,
        ):
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
        original = read_proof_bytes(path)
        detached = load_proof(original)
    except (ValueError, DeserializationError):
        print("INVALID_PROOF", file=sys.stderr)
        return 2
    except OSError:
        print("EXECUTION_ERROR", file=sys.stderr)
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
        if len(upgraded) > MAX_PROOF_BYTES:
            raise ValueError("upgraded proof exceeds size limit")

        atomic_replace(path, upgraded)
        print("UPGRADED", file=sys.stderr)
        return 0
    except Exception:
        print("EXECUTION_ERROR", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
