# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Security and behavior coverage for the focused OTS upgrade helper."""

from __future__ import annotations

import contextlib
import io
import os
import subprocess
import socket
import time
import urllib.error
import runpy
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import opentimestamps.calendar
from opentimestamps.calendar import CommitmentNotFoundError, UrlWhitelist
from opentimestamps.core.notary import BitcoinBlockHeaderAttestation, PendingAttestation
from opentimestamps.core.op import OpSHA256
from opentimestamps.core.serialize import (
    DeserializationError,
    StreamDeserializationContext,
    StreamSerializationContext,
)
from opentimestamps.core.timestamp import DetachedTimestampFile, Timestamp


SCRIPT = Path(__file__).resolve().parents[3] / "scripts" / "ots-upgrade.py"
DIGEST = bytes.fromhex("ab" * 32)
ALLOWED = "https://alice.calendar.opentimestamps.org"
ALLOWED_SECOND = "https://bob.calendar.opentimestamps.org"
UNAPPROVED = "http://127.0.0.1:8000/private"


def proof_with_pending(uri: str) -> bytes:
    timestamp = Timestamp(DIGEST)
    timestamp.attestations.add(PendingAttestation(uri))
    detached = DetachedTimestampFile(OpSHA256(), timestamp)
    output = io.BytesIO()
    detached.serialize(StreamSerializationContext(output))
    return output.getvalue()


def deserialize(proof: bytes) -> DetachedTimestampFile:
    stream = io.BytesIO(proof)
    context = StreamDeserializationContext(stream)
    detached = DetachedTimestampFile.deserialize(context)
    context.assert_eof()
    return detached


class OtsUpgradeTest(unittest.TestCase):
    def run_helper(self, proof: bytes, remote_calendar, allowed=(ALLOWED,)):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "proof.ots"
            path.write_bytes(proof)
            stderr = io.StringIO()
            whitelist = UrlWhitelist(allowed)
            with (
                patch.object(opentimestamps.calendar, "DEFAULT_CALENDAR_WHITELIST", whitelist),
                patch.object(opentimestamps.calendar, "RemoteCalendar", remote_calendar),
                patch.object(sys, "argv", [str(SCRIPT), str(path)]),
                contextlib.redirect_stderr(stderr),
            ):
                try:
                    runpy.run_path(str(SCRIPT), run_name="__main__")
                except SystemExit as exception:
                    exit_code = int(exception.code)
                else:
                    exit_code = 0
            return exit_code, path.read_bytes(), stderr.getvalue()

    def test_pending_proof_remains_byte_identical_when_calendar_has_no_upgrade(self):
        original = proof_with_pending(ALLOWED)

        class Remote:
            def __init__(self, url):
                self.url = url

            def get_timestamp(self, commitment, timeout=None):
                raise CommitmentNotFoundError("pending")

        code, result, stderr = self.run_helper(original, Remote)
        self.assertEqual(1, code)
        self.assertEqual(original, result)
        self.assertIn("STILL_PENDING", stderr)

    def test_allowed_calendar_upgrade_merges_bitcoin_attestation(self):
        original = proof_with_pending(ALLOWED)
        calls = []

        class Remote:
            def __init__(self, url):
                calls.append(("init", url))

            def get_timestamp(self, commitment, timeout=None):
                calls.append(("get", commitment, timeout))
                upgraded = Timestamp(commitment)
                upgraded.attestations.add(BitcoinBlockHeaderAttestation(840_000))
                return upgraded

        code, result, stderr = self.run_helper(original, Remote)
        self.assertEqual(0, code)
        self.assertNotEqual(original, result)
        self.assertIn("UPGRADED", stderr)
        attestations = list(deserialize(result).timestamp.all_attestations())
        self.assertTrue(any(isinstance(att, BitcoinBlockHeaderAttestation) for _, att in attestations))
        self.assertEqual(("init", ALLOWED), calls[0])
        self.assertEqual(DIGEST, calls[1][1])
        self.assertLessEqual(calls[1][2], 2)

    def test_malformed_proof_fails_without_replacing_input(self):
        original = b"not-an-ots-proof"

        class NeverRemote:
            def __init__(self, url):
                raise AssertionError("network path must not be constructed")

        code, result, stderr = self.run_helper(original, NeverRemote)
        self.assertEqual(2, code)
        self.assertEqual(original, result)
        self.assertIn("INVALID_PROOF", stderr)

    def test_unapproved_pending_uri_makes_no_network_request(self):
        original = proof_with_pending(UNAPPROVED)

        class NeverRemote:
            def __init__(self, url):
                raise AssertionError("unapproved URI reached network construction")

        with patch("socket.getaddrinfo", side_effect=AssertionError("unapproved URI reached DNS")):
            code, result, stderr = self.run_helper(original, NeverRemote)
        self.assertEqual(1, code)
        self.assertEqual(original, result)
        self.assertIn("STILL_PENDING", stderr)

    def test_new_non_bitcoin_attestation_does_not_replace_proof(self):
        original = proof_with_pending(ALLOWED)

        class Remote:
            def __init__(self, url):
                pass

            def get_timestamp(self, commitment, timeout=None):
                upgraded = Timestamp(commitment)
                upgraded.attestations.add(PendingAttestation(ALLOWED))
                return upgraded

        code, result, stderr = self.run_helper(original, Remote)
        self.assertEqual(1, code)
        self.assertEqual(original, result)
        self.assertIn("STILL_PENDING", stderr)

    def test_malformed_calendar_response_does_not_block_later_calendar(self):
        timestamp = Timestamp(DIGEST)
        timestamp.attestations.add(PendingAttestation(ALLOWED))
        timestamp.attestations.add(PendingAttestation(ALLOWED_SECOND))
        detached = DetachedTimestampFile(OpSHA256(), timestamp)
        output = io.BytesIO()
        detached.serialize(StreamSerializationContext(output))
        original = output.getvalue()
        calls = []

        class Remote:
            def __init__(self, url):
                self.url = url

            def get_timestamp(self, commitment, timeout=None):
                calls.append(self.url)
                if len(calls) == 1:
                    raise opentimestamps.core.serialize.DeserializationError("malformed response")
                upgraded = Timestamp(commitment)
                upgraded.attestations.add(BitcoinBlockHeaderAttestation(840_000))
                return upgraded

        code, result, stderr = self.run_helper(
            original,
            Remote,
            allowed=(ALLOWED, ALLOWED_SECOND),
        )

        self.assertEqual(0, code)
        self.assertEqual(2, len(calls))
        self.assertNotEqual(original, result)
        self.assertIn("UPGRADED", stderr)

    def test_calendar_work_uses_one_cumulative_wall_clock_deadline(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        detached = deserialize(proof_with_pending(ALLOWED))
        stages = []

        class SlowRemote:
            def __init__(self, url):
                pass

            def get_timestamp(self, commitment, timeout=None):
                for stage in ("resolution", "connect", "tls", "request", "read"):
                    stages.append(stage)
                    time.sleep(0.03)
                raise AssertionError("the cumulative deadline was not enforced")

        runtime["upgrade"].__globals__["REQUEST_TIMEOUT_SECONDS"] = 0.07
        started = time.monotonic()
        with (
            patch.object(
                opentimestamps.calendar,
                "DEFAULT_CALENDAR_WHITELIST",
                UrlWhitelist([ALLOWED]),
            ),
            patch.object(opentimestamps.calendar, "RemoteCalendar", SlowRemote),
        ):
            upgraded = runtime["upgrade"](detached)

        self.assertFalse(upgraded)
        self.assertLess(time.monotonic() - started, 0.5)
        self.assertNotIn("read", stages)

    def test_input_reader_requests_only_the_proof_limit_plus_sentinel(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        requested_sizes = []

        class Reader:
            def __enter__(self):
                return self

            def __exit__(self, *args):
                pass

            def read(self, size):
                requested_sizes.append(size)
                return b"x" * size

        with patch.object(Path, "open", return_value=Reader()):
            with self.assertRaises(DeserializationError):
                runtime["read_proof_bytes"](Path("oversized.ots"))

        self.assertEqual([runtime["MAX_PROOF_BYTES"] + 1], requested_sizes)

    def test_oversized_serialized_upgrade_does_not_replace_input(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        original = proof_with_pending(ALLOWED)

        class Remote:
            def __init__(self, url):
                pass

            def get_timestamp(self, commitment, timeout=None):
                upgraded = Timestamp(commitment)
                upgraded.attestations.add(BitcoinBlockHeaderAttestation(840_000))
                return upgraded

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "proof.ots"
            path.write_bytes(original)
            runtime["main"].__globals__["serialize"] = (
                lambda detached: b"x" * (runtime["MAX_PROOF_BYTES"] + 1)
            )
            stderr = io.StringIO()
            with (
                patch.object(
                    opentimestamps.calendar,
                    "DEFAULT_CALENDAR_WHITELIST",
                    UrlWhitelist([ALLOWED]),
                ),
                patch.object(opentimestamps.calendar, "RemoteCalendar", Remote),
                patch.object(sys, "argv", [str(SCRIPT), str(path)]),
                contextlib.redirect_stderr(stderr),
            ):
                code = runtime["main"]()

            self.assertEqual(2, code)
            self.assertEqual(original, path.read_bytes())
            self.assertEqual("EXECUTION_ERROR\n", stderr.getvalue())

    def test_failed_atomic_replace_preserves_input_and_removes_staging_file(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "proof.ots"
            path.write_bytes(b"original")
            with patch("os.replace", side_effect=OSError("replace failed")):
                with self.assertRaises(OSError):
                    runtime["atomic_replace"](path, b"upgraded")

            self.assertEqual(b"original", path.read_bytes())
            self.assertEqual([path], list(Path(directory).iterdir()))

    def test_directory_fsync_failure_does_not_contradict_committed_upgrade(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "proof.ots"
            path.write_bytes(b"original")
            with patch("os.fsync", side_effect=[None, OSError("directory fsync failed")]):
                runtime["atomic_replace"](path, b"upgraded")

            self.assertEqual(b"upgraded", path.read_bytes())
            self.assertEqual([path], list(Path(directory).iterdir()))

    def test_missing_core_dependency_returns_deterministic_execution_error(self):
        with tempfile.TemporaryDirectory() as directory:
            Path(directory, "opentimestamps.py").write_text(
                "raise ImportError('simulated unavailable core')\n",
                encoding="utf-8",
            )
            environment = os.environ.copy()
            environment["PYTHONPATH"] = directory
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "unused.ots"],
                capture_output=True,
                check=False,
                env=environment,
                timeout=5,
            )

        self.assertEqual(2, result.returncode)
        self.assertEqual(b"EXECUTION_ERROR\n", result.stderr)
        self.assertNotIn(b"Traceback", result.stderr)

    def test_approved_hostname_resolving_private_is_rejected(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        private_result = [(2, 1, 6, "", ("127.0.0.1", 443))]
        with patch("socket.getaddrinfo", return_value=private_result):
            with self.assertRaises(urllib.error.URLError):
                runtime["resolve_public_addresses"]("alice.calendar.opentimestamps.org", 443)

    def test_https_transport_connects_only_to_the_validated_public_address(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        public_address = ("93.184.216.34", 443)
        public_result = [
            (socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, "", public_address),
        ]
        private_result = [
            (socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, "", ("127.0.0.1", 443)),
        ]
        connected = []
        server_names = []

        class FakeSocket:
            def settimeout(self, timeout):
                self.timeout = timeout

            def connect(self, address):
                connected.append(address)

            def close(self):
                pass

        class FakeTlsContext:
            def wrap_socket(self, transport, server_hostname=None):
                server_names.append(server_hostname)
                return transport

        with (
            patch("socket.getaddrinfo", side_effect=[public_result, private_result]) as resolver,
            patch("socket.socket", return_value=FakeSocket()),
            patch("ssl.create_default_context", return_value=FakeTlsContext()),
        ):
            addresses = runtime["resolve_public_addresses"](
                "alice.calendar.opentimestamps.org",
                443,
            )
            connection = runtime["PinnedHTTPSConnection"](
                "alice.calendar.opentimestamps.org",
                443,
                addresses,
                timeout=2,
            )
            connection.connect()

        self.assertEqual(1, resolver.call_count)
        self.assertEqual([public_address], connected)
        self.assertNotIn(("127.0.0.1", 443), connected)
        self.assertEqual(["alice.calendar.opentimestamps.org"], server_names)

    def test_approved_calendar_is_bound_to_expected_request_path(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        expected = f"{ALLOWED}/timestamp/{DIGEST.hex()}"
        _, safe_urlopen = runtime["calendar_urlopen"](expected)

        with patch("socket.getaddrinfo", side_effect=AssertionError("unexpected URI reached DNS")):
            with self.assertRaises(urllib.error.URLError):
                safe_urlopen(f"{ALLOWED}/timestamp/{'cd' * 32}")


if __name__ == "__main__":
    unittest.main()
