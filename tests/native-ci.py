#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Git-backed applicability fixtures; never image or scanner evidence."""
import importlib.util
import copy
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

POLICY = Path(__file__).resolve().parents[1]/'.github/scripts/native-ci.py'
spec = importlib.util.spec_from_file_location('native_ci', POLICY)
ci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci)


class ScopeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.git('init', '-q', '-b', 'main')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('config', 'commit.gpgsign', 'false')
        for name, data in {
            '.containerignore': '.git\n.github\ntests\nCHANGELOG.md\n!docs/ADDRESS_DATA.md\n',
            'Containerfile': 'FROM fixture\nCOPY app /app\n',
            'app/a.php': '<?php // original\n',
            'composer.lock': '{}\n',
            'tests/Unit/a.php': '<?php // original assertion\n',
            'tests/docker/smoke.sh': '#!/bin/sh\nexit 0\n',
            '.github/workflows/php-ci.yml': 'name: CI\n',
        }.items():
            self.write(name, data)
        self.git('add', '.')
        self.git('commit', '-qm', 'accepted base')
        self.base = self.git('rev-parse', 'HEAD')
        self.git('checkout', '-qb', 'pr')

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, stderr=subprocess.DEVNULL).decode().strip()

    def write(self, name, value):
        p = self.root/name
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(value)

    def merge(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'candidate')
        head = self.git('rev-parse', 'HEAD')
        self.git('checkout', '-q', 'main')
        self.git('merge', '--no-ff', '-qm', 'provider merge', 'pr')
        return head, self.git('rev-parse', 'HEAD')

    def scope(self, *, bootstrap=False):
        head, merge = self.merge()
        return ci.scope(self.root, self.base, head, merge, bootstrap=bootstrap)

    def test_assertion_only_has_explicit_nonapplicability_without_clean_or_release_authority(self):
        self.write('tests/Unit/a.php', '<?php // changed assertion\n')
        proof = self.scope()
        self.assertEqual(proof['mode'], 'not-applicable')
        self.assertEqual(proof['source']['base'], self.base)
        self.assertEqual([x['path'] for x in proof['changes']], ['tests/Unit/a.php'])
        self.assertNotIn('CLEAN', str(proof))
        self.assertNotIn('qualification', proof)

    def test_ci_or_native_smoke_changes_require_full_requalification_of_existing_images(self):
        for path in ['.github/workflows/php-ci.yml', 'tests/docker/smoke.sh']:
            with self.subTest(path=path):
                self.write(path, 'changed\n')
        self.assertEqual(self.scope()['mode'], 'reuse')

    def test_bootstrap_cannot_issue_nonapplicability(self):
        self.write('tests/Unit/a.php', 'changed\n')
        self.assertEqual(self.scope(bootstrap=True)['mode'], 'reuse')

    def test_adding_a_regular_test_does_not_require_images(self):
        self.write('tests/Unit/new.php', '<?php // another assertion\n')
        self.assertEqual(self.scope()['mode'], 'not-applicable')

    def test_runtime_dependency_publisher_or_context_changes_require_new_native_builds(self):
        for path in ['app/a.php', 'composer.lock', 'scripts/publish-container.sh', 'Containerfile', '.containerignore']:
            with self.subTest(path=path):
                self.write(path, 'changed\n')
        self.assertEqual(self.scope()['mode'], 'build')

    def test_rename_from_tests_into_runtime_is_not_hidden_by_rename_detection(self):
        self.git('mv', 'tests/Unit/a.php', 'app/new.php')
        self.assertEqual(self.scope()['mode'], 'build')

    def test_symlinks_are_unknown_scope(self):
        (self.root/'tests/Unit/link.php').symlink_to('../../app/a.php')
        with self.assertRaises(ValueError):
            self.scope()

    def test_unrecognized_reinclusion_cannot_prove_context_exclusion(self):
        self.write('.containerignore', '.git\n.github\ntests\n!**/a.php\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'fixture with reinclusion')
        self.git('branch', '-f', 'main')
        self.base = self.git('rev-parse', 'HEAD')
        self.write('tests/Unit/a.php', 'changed\n')
        with self.assertRaises(ValueError):
            self.scope()

    def test_mismatched_parents_and_dirty_or_shallow_sources_fail_closed(self):
        self.write('tests/Unit/a.php', 'changed\n')
        head, merge = self.merge()
        with self.assertRaises(ValueError):
            ci.scope(self.root, head, head, merge)
        self.write('app/a.php', 'dirty\n')
        with self.assertRaises(ValueError):
            ci.scope(self.root, self.base, head, merge)
        self.git('restore', 'app/a.php')
        (self.root/'.git/shallow').write_text(self.base+'\n')
        with self.assertRaises(ValueError):
            ci.scope(self.root, self.base, head, merge)

    def test_pr_policy_edit_cannot_replace_the_protected_base_classifier(self):
        self.write(ci.POLICY, POLICY.read_text())
        self.git('add', '.')
        self.git('commit', '-qm', 'protected CI policy')
        self.git('branch', '-f', 'main')
        self.base = self.git('rev-parse', 'HEAD')
        self.write(ci.POLICY, 'raise SystemExit("forged not-applicable")\n')
        head, merge = self.merge()
        with tempfile.TemporaryDirectory() as temp:
            event = Path(temp)/'event.json'
            event.write_text(json.dumps(dict(pull_request=dict(
                base=dict(repo=dict(full_name='SecPal/api'), ref='main', sha=self.base),
                head=dict(sha=head)))))
            environment = dict(GITHUB_REPOSITORY='SecPal/api', GITHUB_EVENT_NAME='pull_request',
                               GITHUB_SHA=merge, GITHUB_EVENT_PATH=str(event))
            with patch.dict(os.environ, environment), patch.object(ci, 'api', return_value=dict(object=dict(sha=self.base))):
                self.assertEqual(ci.current_scope(self.root, False)['mode'], 'reuse')
                counterfeit = Path(temp)/'policy.py'
                counterfeit.write_text('forged\n')
                with patch.object(ci, '__file__', str(counterfeit)), self.assertRaises(ValueError):
                    ci.current_scope(self.root, False)


class ArchiveTests(unittest.TestCase):
    def test_unsafe_paths_links_and_duplicate_members_are_rejected(self):
        for bad in ('../outside', '/absolute', 'a/../outside', 'a\\outside', 'symlink', 'duplicate'):
            with self.subTest(bad=bad), tempfile.TemporaryDirectory() as temp:
                path = Path(temp)/'evidence.zip'
                with zipfile.ZipFile(path, 'w') as z:
                    if bad == 'symlink':
                        info = zipfile.ZipInfo('link')
                        info.external_attr = (stat.S_IFLNK | 0o777) << 16
                        z.writestr(info, '../outside')
                    elif bad == 'duplicate':
                        z.writestr('same', 'first')
                        with self.assertWarns(UserWarning):
                            z.writestr('same', 'second')
                    else:
                        z.writestr(bad, 'untrusted')
                with self.assertRaises(ValueError):
                    ci.checked_zip(path)

    def test_checksum_is_checked_before_zip_is_parsed(self):
        artifact = dict(id=42, expired=False, digest='sha256:'+'0'*64)
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp)/'evidence.zip'
            def response(*args, **kwargs):
                kwargs['stdout'].write(b'not even a zip archive')
            with patch.object(ci.subprocess, 'run', side_effect=response), self.assertRaises(ValueError):
                ci.archive(artifact, path)

    def test_self_consistent_replacement_image_cannot_replace_the_accepted_subject(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            context = dict(source_commit='a'*40, run_id='123', run_attempt='2')
            records = {}
            for role in (*ci.ROLES, 'legacy'):
                own = root/role
                own.mkdir()
                record = dict(source=dict(commit=context['source_commit'], runtime=role), bases=[],
                              subject=dict(manifest_digest='sha256:'+'b'*64), runner_architecture='amd64',
                              workflow_run_id='123', workflow_run_attempt='2')
                records[role] = copy.deepcopy(record)
                ci.write(own/'record.json', record)
            proof = root/'scope.json'
            ci.write(proof, dict(baseline=dict(context=context, records={r:dict(amd64=records[r]) for r in ci.ROLES})))
            pub = dict(source=lambda _root, commit, role: dict(commit=commit, runtime=role),
                       bases=lambda *args: [],
                       read_layout=lambda layout, *args: (json.loads((layout.parent/'record.json').read_text())['subject'], b'', b''))
            with patch.object(ci, 'publisher', return_value=pub), patch.object(ci, 'base_checkout', return_value=root):
                self.assertEqual(ci.observe(root, proof, root, 'amd64')['php-fpm'], 'sha256:'+'b'*64)
                altered = copy.deepcopy(records['php-fpm'])
                altered['subject']['manifest_digest'] = 'sha256:'+'c'*64
                ci.write(root/'php-fpm/record.json', altered)
                with self.assertRaises(ValueError):
                    ci.observe(root, proof, root, 'amd64')


if __name__ == '__main__':
    unittest.main()
