# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

"""Script-level regression coverage for OpenTimestamps calendar submission."""

from __future__ import annotations

import contextlib
import io
import runpy
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[3] / "scripts" / "ots-stamp-hash.py"
DIGEST = "a" * 64


class OtsStampHashTest(unittest.TestCase):
    def run_script(self, outcomes: dict[str, object]):
        submissions: list[str] = []

        class Timestamp:
            def __init__(self, message: bytes):
                self.msg = message
                self.responses: list[str] = []

            def merge(self, response):
                self.responses.append(response.url)

        class DetachedTimestampFile:
            def __init__(self, operation, timestamp):
                self.timestamp = timestamp

            def serialize(self, context):
                context.stream.write(
                    b"proof:" + b"|".join(url.encode() for url in self.timestamp.responses)
                )

        class RemoteCalendar:
            def __init__(self, url: str):
                self.url = url

            def submit(self, message: bytes):
                submissions.append(self.url)
                outcome = outcomes[self.url]
                if isinstance(outcome, Exception):
                    raise outcome

                return types.SimpleNamespace(url=self.url)

        modules = {
            "opentimestamps": types.ModuleType("opentimestamps"),
            "opentimestamps.calendar": types.SimpleNamespace(RemoteCalendar=RemoteCalendar),
            "opentimestamps.cmds": types.SimpleNamespace(
                DEFAULT_CALENDAR_URLS=list(outcomes)
            ),
            "opentimestamps.core": types.ModuleType("opentimestamps.core"),
            "opentimestamps.core.timestamp": types.SimpleNamespace(
                Timestamp=Timestamp, DetachedTimestampFile=DetachedTimestampFile
            ),
            "opentimestamps.core.op": types.SimpleNamespace(OpSHA256=object),
            "opentimestamps.core.serialize": types.SimpleNamespace(
                StreamSerializationContext=lambda stream: types.SimpleNamespace(stream=stream)
            ),
        }
        modules["opentimestamps"].calendar = modules["opentimestamps.calendar"]

        stdout = io.TextIOWrapper(io.BytesIO(), encoding="utf-8")
        stderr = io.StringIO()
        with (
            patch.dict(sys.modules, modules),
            patch.object(sys, "argv", [str(SCRIPT), DIGEST]),
            contextlib.redirect_stdout(stdout),
            contextlib.redirect_stderr(stderr),
        ):
            runtime = runpy.run_path(str(SCRIPT), run_name="ots_stamp_hash_test")
            try:
                runpy.run_path(str(SCRIPT), run_name="__main__")
            except SystemExit as exception:
                exit_code = exception.code
            else:
                exit_code = 0

        stdout.flush()
        return exit_code, stdout.buffer.getvalue(), stderr.getvalue(), submissions, runtime

    def test_merges_every_successful_calendar_response_and_accepts_one(self):
        urls = {
            "https://first.example": object(),
            "https://failed.example": RuntimeError("offline"),
            "https://last.example": object(),
        }

        exit_code, proof, stderr, submissions, runtime = self.run_script(urls)

        self.assertEqual(1, runtime["MINIMUM_SUCCESSFUL_SUBMISSIONS"])
        self.assertEqual(0, exit_code)
        self.assertEqual(list(urls), submissions)
        self.assertEqual(b"proof:https://first.example|https://last.example", proof)
        self.assertIn("Success: Created proof with 2 calendar attestations", stderr)

    def test_succeeds_when_exactly_one_calendar_submission_succeeds(self):
        urls = {
            "https://failed.example": RuntimeError("offline"),
            "https://successful.example": object(),
        }

        exit_code, proof, stderr, submissions, runtime = self.run_script(urls)

        self.assertEqual(1, runtime["MINIMUM_SUCCESSFUL_SUBMISSIONS"])
        self.assertEqual(0, exit_code)
        self.assertEqual(list(urls), submissions)
        self.assertEqual(b"proof:https://successful.example", proof)
        self.assertIn("Success: Created proof with 1 calendar attestations", stderr)

    def test_fails_only_when_no_calendar_submissions_succeed(self):
        urls = {"https://failed.example": RuntimeError("offline")}

        exit_code, proof, stderr, submissions, runtime = self.run_script(urls)

        self.assertEqual(1, runtime["MINIMUM_SUCCESSFUL_SUBMISSIONS"])
        self.assertEqual(1, exit_code)
        self.assertEqual(list(urls), submissions)
        self.assertEqual(b"", proof)
        self.assertIn("Error: Failed to submit to at least 1 calendar server", stderr)
