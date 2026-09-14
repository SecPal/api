# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Security and behavior coverage for the focused OTS upgrade helper."""

from __future__ import annotations

import contextlib
import io
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
from opentimestamps.core.serialize import StreamDeserializationContext, StreamSerializationContext
from opentimestamps.core.timestamp import DetachedTimestampFile, Timestamp


SCRIPT = Path(__file__).resolve().parents[3] / "scripts" / "ots-upgrade.py"
DIGEST = bytes.fromhex("ab" * 32)
ALLOWED = "https://alice.calendar.opentimestamps.org"
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
    def run_helper(self, proof: bytes, remote_calendar):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "proof.ots"
            path.write_bytes(proof)
            stderr = io.StringIO()
            whitelist = UrlWhitelist([ALLOWED])
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

    def test_approved_hostname_resolving_private_is_rejected(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        private_result = [(2, 1, 6, "", ("127.0.0.1", 443))]
        with patch("socket.getaddrinfo", return_value=private_result):
            with self.assertRaises(urllib.error.URLError):
                runtime["require_public_address"]("alice.calendar.opentimestamps.org", 443)

    def test_approved_calendar_is_bound_to_expected_request_path(self):
        runtime = runpy.run_path(str(SCRIPT), run_name="ots_upgrade_test")
        expected = f"{ALLOWED}/timestamp/{DIGEST.hex()}"
        _, safe_urlopen = runtime["calendar_urlopen"](expected)

        with patch("socket.getaddrinfo", side_effect=AssertionError("unexpected URI reached DNS")):
            with self.assertRaises(urllib.error.URLError):
                safe_urlopen(f"{ALLOWED}/timestamp/{'cd' * 32}")


if __name__ == "__main__":
    unittest.main()
