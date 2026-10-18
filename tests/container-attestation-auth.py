#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Exact-job credential handoff tests; synthetic credentials, no remote writes."""
import base64
import importlib.util
from http.server import BaseHTTPRequestHandler, HTTPServer
import threading
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

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
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / ".context/issue-1445")
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
            def do_GET(inner):
                valid = inner.path == "/v2/" and inner.headers.get("Authorization") == "Basic " + AUTH
                if valid:
                    accepted.append(True)
                inner.send_response(200 if valid else 401)
                if not valid:
                    inner.send_header("WWW-Authenticate", 'Basic realm="synthetic"')
                inner.end_headers()
            def log_message(inner, *_):
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
        with mock.patch.object(handoff.os, "geteuid", return_value=os.geteuid() + 1):
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
        with mock.patch.object(handoff.os, "replace", side_effect=interrupted):
            with self.assertRaises(OSError): self.install()
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_text(), "previous")

    def test_cli_rejects_pr_context_without_accessing_default_home(self):
        env = dict(os.environ, GITHUB_REPOSITORY="SecPal/api", GITHUB_EVENT_NAME="pull_request",
                   GITHUB_REF="refs/pull/1/merge", RUNNER_ENVIRONMENT="github-hosted",
                   GITHUB_WORKFLOW_REF="SecPal/api/.github/workflows/publish-container.yml@refs/heads/main",
                   GITHUB_JOB="attest", GITHUB_RUN_ID="123", GITHUB_RUN_ATTEMPT="1",
                   RUNNER_TEMP=str(self.root), GITHUB_ACTOR=ACTOR)
        result = subprocess.run(["python3", str(ROOT / "scripts/container-attestation-auth.py"), "prepare"],
                                env=env, capture_output=True, text=True, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("ATTESTATION_CREDENTIAL_OPERATION_FAILED", result.stderr)
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
        with mock.patch.object(handoff.os, "replace", side_effect=OSError("synthetic restoration failure")):
            with self.assertRaises(OSError):
                handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertTrue(self.private.exists())
        handoff.cleanup(self.home, self.private, IDENTITY)
        self.assertEqual(self.target.read_text(), "previous")

if __name__ == "__main__":
    unittest.main()
