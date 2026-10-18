#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Exact-job credential handoff tests; synthetic credentials, no remote writes."""
import base64
import importlib.util
from contextlib import redirect_stdout
import io
from http.server import BaseHTTPRequestHandler, HTTPServer
import threading
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest.mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("handoff", ROOT / "scripts/container-attestation-auth.py")
handoff = importlib.util.module_from_spec(spec)
spec.loader.exec_module(handoff)
IDENTITY = {"run": "123", "attempt": "1", "job": "attest"}
ACTOR = "fixture-actor"
AUTH = base64.b64encode((ACTOR + ":synthetic-only-token").encode()).decode()

# Load the actual pinned bundle's auth/name module factories, not a copied parser.
READER = r"""
const fs=require('node:fs'), vm=require('node:vm'), crypto=require('node:crypto');
const raw=fs.readFileSync(process.argv[1],'utf8'), home=process.argv[2];
if(crypto.createHash('sha256').update(raw).digest('hex')!==
 '3ca89e06ffcb09ff97e9b1633575865cad0b23310ac82176d72990d7554836b5')throw Error('Wrong pinned action');
const cache={};
function load(id){
 if(id===73024)return require('node:fs');
 if(id===48161)return {...require('node:os'),homedir:()=>home};
 if(id===76760)return require('node:path');
 if(cache[id])return cache[id].exports;
 const start=raw.indexOf('/***/ '+id+':\n');
 if(start<0)throw Error('Missing pinned module');
 const bodyStart=raw.indexOf('/***/ ',start+10)+'/***/ '.length;
 const end=raw.indexOf('\n/***/ }),',bodyStart);
 const text=raw.slice(bodyStart,end)+'})';
 const module={exports:{}};cache[id]=module;
 const native=(name)=>{
  if(name==='node:os')return {...require(name),homedir:()=>home};
  if(!['node:fs','node:path'].includes(name))throw Error('Unexpected module');
  return require(name);
 };
 const factory=vm.runInNewContext(text,{require:native,Buffer,URL});
 factory(module,module.exports,load);
 return module.exports;
}
try{
 const result=load(62691).getRegistryCredentials('ghcr.io/secpal/api');
 const expected=JSON.parse(fs.readFileSync(0,'utf8'));
 if(result.username!==expected.username||result.password!==expected.password)throw Error('Identity mismatch');
 console.log('Pinned credential reader: PASS');
}catch(error){console.error('Pinned credential reader: REJECTED');process.exit(1);}
"""

class Handoff(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix=".attestation-test-", dir=ROOT)
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.home = self.root / "home"
        self.home.mkdir(mode=0o700)
        self.private = self.root / "private"
        handoff.prepare(self.private, IDENTITY)
        self.source = self.private / "config.json"
        self.source.write_text(json.dumps({"auths": {"ghcr.io": {"auth": AUTH},
                                                    "unrelated.invalid": {"auth": "not-forwarded"}}}))
        self.source.chmod(0o600)
        self.target = self.home / ".docker/config.json"

    def install(self):
        handoff.install(self.home, self.private, IDENTITY, ACTOR)

    def reader(self):
        return subprocess.run(
            ["node", "-e", READER, os.environ["SECPAL_TEST_ATTEST_BUNDLE"], str(self.home)],
            input=json.dumps({"username": ACTOR, "password": "synthetic-only-token"}),
            text=True, capture_output=True, check=False)

    def test_diagnostics_distinguish_source_default_journal_and_atomic_failure_without_values(self):
        self.source.chmod(0o644)
        with self.assertRaises(handoff.Rejected) as rejected:
            self.install()
        self.assertEqual((rejected.exception.phase, rejected.exception.code),
                         ("SOURCE_CREDENTIAL", "FILE_MODE"))
        self.source.chmod(0o600)
        self.source.write_text('{"auths":{"ghcr.io":{"auth":"private-malformed-content"}}}')
        with self.assertRaises(handoff.Rejected) as rejected:
            self.install()
        self.assertEqual((rejected.exception.phase, rejected.exception.code),
                         ("SOURCE_CREDENTIAL", "AUTH_ENCODING"))
        self.assertNotIn("private-malformed-content", str(rejected.exception))
        self.source.write_text(json.dumps({"auths": {"ghcr.io": {"auth": AUTH}}}))
        self.target.parent.mkdir(mode=0o700)
        self.target.write_text("private-existing-content")
        self.target.chmod(0o644)
        with self.assertRaises(handoff.Rejected) as rejected:
            self.install()
        self.assertEqual((rejected.exception.phase, rejected.exception.code),
                         ("DEFAULT_READER_STATE", "FILE_MODE"))
        self.assertNotIn(str(self.home), str(rejected.exception))
        self.assertEqual(self.target.read_text(), "private-existing-content")
        self.target.chmod(0o600)
        with unittest.mock.patch.object(handoff.os, "replace",
                                       side_effect=PermissionError(13, "private-path-and-token")):
            with self.assertRaises(handoff.Rejected) as rejected:
                self.install()
        self.assertEqual((rejected.exception.phase, rejected.exception.code),
                         ("JOURNAL", "OS_PERMISSION_DENIED"))
        self.assertNotIn("private-path-and-token", str(rejected.exception))

    def test_diagnostics_reject_wrong_journal_and_unsafe_parent_without_values(self):
        with handoff.directory(self.private) as fd:
            with self.assertRaises(handoff.Rejected) as rejected:
                handoff.load(fd, dict(IDENTITY, attempt="2"))
        self.assertEqual((rejected.exception.phase, rejected.exception.code),
                         ("JOURNAL", "JOURNAL_IDENTITY"))
        self.home.chmod(0o777)
        with self.assertRaises(handoff.Rejected) as rejected:
            self.install()
        self.assertEqual((rejected.exception.phase, rejected.exception.code),
                         ("HOME_PATH", "DIRECTORY_WRITE_ACCESS"))
        self.assertNotIn(str(self.home), str(rejected.exception))
        self.home.chmod(0o700)

    def test_hosted_metadata_probe_is_read_only_and_reports_only_a_fixed_condition(self):
        self.target.parent.mkdir(mode=0o700)
        self.target.write_text("private-existing-content")
        self.target.chmod(0o644)
        before = self.target.stat()
        output = io.StringIO()
        with unittest.mock.patch.dict(os.environ, GITHUB_ACTIONS="true",
                                      RUNNER_ENVIRONMENT="github-hosted"), \
                unittest.mock.patch.object(Path, "home", return_value=self.home), \
                unittest.mock.patch.object(handoff, "read_file") as read_file, \
                redirect_stdout(output):
            observe_hosted_default_metadata()
        self.assertEqual(output.getvalue(),
                         "HOSTED_METADATA_OBSERVATION phase=DEFAULT_READER_STATE code=FILE_MODE\n")
        read_file.assert_not_called()
        self.assertEqual(self.target.stat(), before)
        self.assertEqual(self.target.read_text(), "private-existing-content")

    def test_actual_pinned_reader_requires_handoff_and_only_receives_ghcr(self):
        self.assertNotEqual(self.reader().returncode, 0)
        original = self.source.read_bytes()
        self.install()
        result = self.reader()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(self.target.read_text()), {"auths": {"ghcr.io": {"auth": AUTH}}})
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.target.parent.stat().st_mode & 0o777, 0o700)
        self.assertEqual(self.source.read_bytes(), original)
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertFalse(self.private.exists())
        self.assertFalse(self.target.parent.exists())

    def test_actual_oras_login_private_store_then_actual_pinned_reader(self):
        self.source.unlink()
        accepted = []
        class Registry(BaseHTTPRequestHandler):
            def do_GET(self):
                valid = self.path == "/v2/" and self.headers.get("Authorization") == "Basic " + AUTH
                if valid:
                    accepted.append(True)
                self.send_response(200 if valid else 401)
                if not valid:
                    self.send_header("WWW-Authenticate", 'Basic realm="synthetic"')
                self.end_headers()
            def log_message(self, *_):
                pass
        server = HTTPServer(("127.0.0.1", 0), Registry)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            env = {k: v for k, v in os.environ.items() if k.lower() not in
                   ("http_proxy", "https_proxy", "all_proxy")}
            result = subprocess.run([
                os.environ["SECPAL_TEST_ORAS"], "login", "ghcr.io", "--plain-http",
                "--resolve", "ghcr.io:80:127.0.0.1:" + str(server.server_port),
                "--registry-config", str(self.source), "--username", ACTOR, "--password-stdin"
            ], input="synthetic-only-token", capture_output=True, text=True, env=env, check=False)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(accepted)
            self.assertEqual(json.loads(self.source.read_text())["auths"]["ghcr.io"]["auth"], AUTH)
            self.install()
            self.assertEqual(self.reader().returncode, 0)
            handoff.cleanup(self.home, self.private, IDENTITY)
        finally:
            server.shutdown()
            server.server_close()
            worker.join()

    def test_prior_credential_inode_bytes_mode_and_directory_are_restored(self):
        self.target.parent.mkdir(mode=0o755)
        original = b'{"auths":{"unrelated.invalid":{"auth":"synthetic-prior"}}}\n'
        self.target.write_bytes(original)
        self.target.chmod(0o600)
        inode = self.target.stat().st_ino
        self.install()
        handoff.restore(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_bytes(), original)
        self.assertEqual(self.target.stat().st_ino, inode)
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.target.parent.stat().st_mode & 0o777, 0o755)
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_bytes(), original)

    def test_symlink_unsafe_mode_owner_and_hardlink_are_rejected_before_mutation(self):
        self.target.parent.mkdir(mode=0o700)
        untouched = self.root / "untouched"
        untouched.write_text("unchanged")
        self.target.symlink_to(untouched)
        with self.assertRaises((handoff.Rejected, OSError, ValueError, KeyError)): self.install()
        self.assertEqual(untouched.read_text(), "unchanged")
        self.target.unlink()
        self.target.write_text("{}")
        self.target.chmod(0o644)
        with self.assertRaises((handoff.Rejected, OSError, ValueError, KeyError)): self.install()
        self.assertEqual(self.target.read_text(), "{}")
        self.target.chmod(0o600)
        os.link(self.target, self.root / "other-link")
        with self.assertRaises((handoff.Rejected, OSError, ValueError, KeyError)): self.install()
        self.target.unlink()
        self.target.parent.rmdir()
        self.target.parent.symlink_to(self.root)
        with self.assertRaises((handoff.Rejected, OSError, ValueError, KeyError)): self.install()
        self.target.parent.unlink()
        with unittest.mock.patch.object(handoff.os, "geteuid", return_value=os.geteuid() + 1):
            with self.assertRaises(handoff.Rejected): self.install()
        self.assertFalse(self.target.parent.exists())

    def test_missing_malformed_incomplete_wrong_actor_and_unsafe_source_rejected(self):
        for candidate in [b"", b"{", b'{"auths":{}}',
                          b'{"auths":{"ghcr.io":{"auth":"invalid"}}}',
                          json.dumps({"auths":{"ghcr.io":{"auth":base64.b64encode(b"wrong:token").decode()}}}).encode()]:
            self.source.write_bytes(candidate)
            with self.assertRaises((handoff.Rejected, OSError, ValueError, KeyError)): self.install()
            self.assertFalse(self.target.parent.exists())
        self.source.write_text(json.dumps({"auths": {"ghcr.io": {"auth": AUTH}}}))
        self.source.chmod(0o644)
        with self.assertRaises(handoff.Rejected): self.install()
        self.source.unlink()
        with self.assertRaises((handoff.Rejected, OSError)): self.install()

    def test_cleanup_rejects_foreign_replacement_and_preserves_owned_recovery_state(self):
        self.install()
        self.target.unlink()
        self.target.write_text("foreign")
        self.target.chmod(0o600)
        with self.assertRaises(handoff.Rejected):
            handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_text(), "foreign")
        self.assertTrue(self.private.exists())

    def test_interrupted_publication_restores_prior_state(self):
        self.target.parent.mkdir(mode=0o700)
        self.target.write_text("previous")
        self.target.chmod(0o600)
        real_replace = handoff.os.replace
        def interrupted(src, dst, **kwargs):
            if dst == "config.json":
                raise OSError("synthetic interruption")
            return real_replace(src, dst, **kwargs)
        with unittest.mock.patch.object(handoff.os, "replace", side_effect=interrupted):
            with self.assertRaises(handoff.Rejected): self.install()
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_text(), "previous")

    def test_interrupted_journal_replacement_is_reconciled_before_cleanup(self):
        real_replace = handoff.os.replace
        replacements = 0
        def interrupted(src, dst, **kwargs):
            nonlocal replacements
            if src == handoff.MARKER + ".next":
                replacements += 1
                if replacements == 3:
                    raise OSError("synthetic journal interruption after candidate inode recording")
            return real_replace(src, dst, **kwargs)
        with unittest.mock.patch.object(handoff.os, "replace", side_effect=interrupted):
            with self.assertRaises(handoff.Rejected):
                self.install()
        self.assertTrue((self.private / (handoff.MARKER + ".next")).exists())
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertFalse(self.private.exists())
        self.assertFalse(self.target.parent.exists())

    def test_interrupted_directory_removal_is_reconciled_before_cleanup(self):
        self.install()
        real_rmdir = handoff.os.rmdir
        def interrupted(path, **kwargs):
            real_rmdir(path, **kwargs)
            if path == ".docker":
                raise OSError("synthetic lost removal acknowledgment")
        with unittest.mock.patch.object(handoff.os, "rmdir", side_effect=interrupted):
            with self.assertRaises(handoff.Rejected):
                handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertFalse(self.target.parent.exists())
        self.assertTrue(self.source.exists())
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertFalse(self.private.exists())

    def test_cli_rejects_pr_context_without_accessing_default_home(self):
        env = dict(os.environ, GITHUB_REPOSITORY="SecPal/api", GITHUB_EVENT_NAME="pull_request",
                   GITHUB_REF="refs/pull/1/merge", RUNNER_ENVIRONMENT="github-hosted",
                   GITHUB_WORKFLOW_REF="SecPal/api/.github/workflows/publish-container.yml@refs/heads/main",
                   GITHUB_JOB="attest", GITHUB_RUN_ID="123", GITHUB_RUN_ATTEMPT="1",
                   RUNNER_TEMP=str(self.root), GITHUB_ACTOR=ACTOR)
        result = subprocess.run(["python3", str(ROOT / "scripts/container-attestation-auth.py"), "prepare"],
                                env=env, capture_output=True, text=True, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("ATTESTATION_CREDENTIAL_OPERATION_FAILED phase=CONTEXT code=CONTEXT_IDENTITY",
                      result.stderr)
        self.assertNotIn(ACTOR, result.stdout + result.stderr)
        self.assertNotIn(str(self.root), result.stdout + result.stderr)
        self.assertNotIn(AUTH, result.stdout + result.stderr)
        self.assertFalse((self.root / "secpal-attest-auth").exists())

    def test_wrong_job_and_unrelated_files_prevent_cleanup(self):
        self.install()
        with self.assertRaises(handoff.Rejected):
            handoff.cleanup(self.home, self.private, dict(IDENTITY, job="accept"))
        extra = self.target.parent / "operator-owned"
        extra.write_text("preserve")
        with self.assertRaises((handoff.Rejected, OSError)):
            handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(extra.read_text(), "preserve")

    def test_restoration_failure_is_not_reported_as_success(self):
        self.target.parent.mkdir(mode=0o700)
        self.target.write_text("previous")
        self.target.chmod(0o600)
        self.install()
        with unittest.mock.patch.object(handoff.os, "replace", side_effect=OSError("synthetic restoration failure")):
            with self.assertRaises(handoff.Rejected):
                handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertTrue(self.private.exists())
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_text(), "previous")

def observe_hosted_default_metadata():
    """Observation only: no registry token, credential reads, writes or admission."""
    if (os.environ.get("GITHUB_ACTIONS") != "true"
            or os.environ.get("RUNNER_ENVIRONMENT") != "github-hosted"):
        return
    try:
        with handoff.boundary("DEFAULT_READER_STATE"):
            with handoff.directory(Path.home(), "HOME_PATH") as home_fd:
                try:
                    fd = os.open(".docker", handoff.FLAGS | os.O_DIRECTORY, dir_fd=home_fd)
                except FileNotFoundError:
                    print("HOSTED_METADATA_OBSERVATION phase=DEFAULT_READER_STATE code=ABSENT")
                    return
                try:
                    handoff.reader_metadata(fd)
                finally:
                    os.close(fd)
        print("HOSTED_METADATA_OBSERVATION phase=DEFAULT_READER_STATE code=METADATA_ADMITTED")
    except handoff.Rejected as failure:
        print("HOSTED_METADATA_OBSERVATION phase=" + failure.phase + " code=" + failure.code)


if __name__ == "__main__":
    observe_hosted_default_metadata()
    unittest.main()
