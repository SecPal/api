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
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

sys.dont_write_bytecode = True

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

    def test_config_change_builds_when_published_source_has_different_runtime_inputs(self):
        producer = self.base
        self.write('Containerfile', 'FROM newer-fixture\nCOPY app /app\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'change native build inputs')
        self.git('branch', '-f', 'main')
        self.base = self.git('rev-parse', 'HEAD')
        self.write('.github/dependabot.yml', 'version: 2\nupdates: []\n')
        scope = self.scope()
        self.assertEqual(scope['mode'], 'reuse')
        with tempfile.TemporaryDirectory() as temp:
            proof = Path(temp)/'proof.json'
            outputs = Path(temp)/'outputs'
            with patch.object(ci, 'current_scope', side_effect=lambda *_: dict(scope)), \
                    patch.object(ci, 'successful_runs', return_value=[dict(id=1, head_sha=producer)]), \
                    patch.object(ci, 'origin') as origin, \
                    patch.object(sys, 'argv', ['native-ci.py', 'plan', '--root', str(self.root), '--proof', str(proof)]), \
                    patch.dict(os.environ, GITHUB_OUTPUT=str(outputs)):
                ci.main()
                strict = ci.verify(self.root, proof)
                self.assertEqual(strict['mode'], 'build')
                self.assertNotIn('baseline', strict)
                self.assertEqual(outputs.read_text(), 'mode=build\n')
                origin.assert_not_called()
                ci.write(proof, dict(strict, mode='not-applicable'))
                with self.assertRaises(ValueError):
                    ci.verify(self.root, proof)
                ci.write(proof, dict(strict, baseline={}))
                with self.assertRaises(ValueError):
                    ci.verify(self.root, proof)

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

    def test_normalized_reinclusions_fail_closed(self):
        for rule in ['!./tests/Unit/a.php', '!foo/../tests/Unit/a.php', '!foo/../.github/ci.py', '!foo/../CHANGELOG.md']:
            with self.subTest(rule=rule):
                self.write('.containerignore', '.git\n.github\ntests\nCHANGELOG.md\n'+rule+'\n')
                self.git('add', '.')
                self.git('commit', '-qm', 'independent reinclusion fixture')
                with self.assertRaises(ValueError):
                    ci.excluded_context(self.root, self.git('rev-parse', 'HEAD'),
                                        [dict(path=p) for p in ('tests/Unit/a.php', '.github/ci.py', 'CHANGELOG.md')])

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

    def test_missing_protected_blob_is_not_initial_adoption(self):
        self.write(ci.POLICY, POLICY.read_text())
        self.git('add', '.')
        self.git('commit', '-qm', 'protected CI policy')
        self.git('branch', '-f', 'main')
        self.base = self.git('rev-parse', 'HEAD')
        self.write('tests/Unit/a.php', 'changed\n')
        head, merge = self.merge()
        blob = self.git('rev-parse', self.base+':'+ci.POLICY)
        (self.root/'.git/objects'/blob[:2]/blob[2:]).unlink()
        with tempfile.TemporaryDirectory() as temp:
            event = Path(temp)/'event.json'
            event.write_text(json.dumps(dict(pull_request=dict(
                base=dict(repo=dict(full_name='SecPal/api'), ref='main', sha=self.base),
                head=dict(sha=head)))))
            environment = dict(GITHUB_REPOSITORY='SecPal/api', GITHUB_EVENT_NAME='pull_request',
                               GITHUB_SHA=merge, GITHUB_EVENT_PATH=str(event))
            with patch.dict(os.environ, environment), patch.object(ci, 'api', return_value=dict(object=dict(sha=self.base))):
                with self.assertRaises((ValueError, subprocess.CalledProcessError)):
                    ci.current_scope(self.root, True)


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


class ScanTests(unittest.TestCase):
    def test_reuse_requalifies_exact_images_with_fresh_healthy_evidence(self):
        for case in ('CLEAN', 'ACTIONABLE', 'REVIEW_REQUIRED', 'UNKNOWN_STALE',
                     'stale', 'failed-scan', 'subject', 'smoke', 'scope'):
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                root=Path(directory)
                base=root/'producer';base.mkdir()
                output=root/'scan';native=root/'native'
                proof_path=root/'scope.json';proof_path.write_text('{}')
                context=dict(source_commit='a'*40,run_id='10',run_attempt='2')
                proof=dict(mode='reuse',baseline=dict(context=context))
                subjects={arch: {role: 'sha256:'+str(num)*64 for num,role in enumerate(ci.ROLES,1)}
                          for arch in ('amd64','arm64')}
                for arch in subjects:
                    folder=native/f'api-native-{arch}-20-3';folder.mkdir(parents=True)
                    ci.write(folder/'ci-smoke.json',dict(
                        subjects=subjects[arch],arch=arch,
                        result='FAILED' if case=='smoke' else 'SUCCEEDED',
                        run_id='20',attempt='3',scope_sha256=ci.digest(proof_path.read_bytes())))
                policy=dict(gate_state=case if case.isupper() else 'CLEAN',
                            evidence_health=dict(state='UNKNOWN_STALE' if case=='stale' else 'HEALTHY'),
                            findings=[] if case=='CLEAN' else [dict(id='fixture-observation')])

                def run_command(command, **kwargs):
                    if command[:1]==['git']:
                        return
                    self.assertEqual(command,['scripts/scan-container-artifact.sh','local'])
                    self.assertEqual(kwargs['cwd'],base)
                    self.assertEqual(kwargs['env']['GITHUB_SHA'],context['source_commit'])
                    self.assertEqual(kwargs['env']['GITHUB_RUN_ID'],context['run_id'])
                    self.assertEqual(kwargs['env']['GITHUB_RUN_ATTEMPT'],context['run_attempt'])
                    if case=='failed-scan':
                        raise subprocess.CalledProcessError(1,command)
                    for arch in subjects:
                        for role,target in subjects[arch].items():
                            folder=base/'.context/scan'/role/arch
                            folder.mkdir(parents=True)
                            ci.write(folder/'evaluation.json',dict(subject=dict(
                                platform='linux/'+arch,oci_digest='sha256:'+'f'*64 if case=='subject' else target)))
                            ci.write(folder/'policy-result.json',policy)

                pub=ci.runpy.run_path(str(POLICY.parents[2]/'scripts/container-publisher.py'))
                pub['observe_policy']=lambda _root, folder, _at: json.loads((folder/'policy-result.json').read_text())
                scope=[proof, ValueError('changed scope') if case=='scope' else proof]
                with patch.dict(os.environ,GITHUB_RUN_ID='20',GITHUB_RUN_ATTEMPT='4',
                                API_NATIVE_AMD64_ATTEMPT='3',API_NATIVE_ARM64_ATTEMPT='3'), \
                        patch.object(ci,'verify',side_effect=scope), \
                        patch.object(ci,'base_checkout',return_value=base), \
                        patch.object(ci,'git',return_value=b'fixture-commit'), \
                        patch.object(ci,'observe',side_effect=lambda _root,_proof,_folder,arch: subjects[arch]), \
                        patch.object(ci,'publisher',return_value=pub), \
                        patch.object(ci.subprocess,'run',side_effect=run_command):
                    receipt=output/'requalified-existing-images.json'
                    if case in ('CLEAN','ACTIONABLE','REVIEW_REQUIRED'):
                        ci.scan(root,proof_path,native,output)
                        value=json.loads(receipt.read_text())
                        self.assertEqual(value['qualification'],'SUCCEEDED')
                        self.assertEqual(value['producer'],context)
                        self.assertEqual(value['verification'],dict(run_id='20',attempt='4'))
                        self.assertEqual(value['subjects'],subjects)
                        self.assertEqual(len(value['policies']),4)
                        for arch in subjects:
                            for role in ci.ROLES:
                                self.assertEqual(json.loads((output/role/arch/'policy-result.json').read_text()),policy)
                    else:
                        with self.assertRaises((ValueError,subprocess.CalledProcessError)):
                            ci.scan(root,proof_path,native,output)
                        self.assertFalse(receipt.exists())


if __name__ == '__main__':
    unittest.main()
