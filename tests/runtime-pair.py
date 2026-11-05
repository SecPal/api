#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pair-binding unit fixtures; not native build, scanner or publication evidence."""
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import unittest
import os
import subprocess
from unittest import mock
import tempfile

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('publisher', Path(__file__).resolve().parents[1] / 'scripts/container-publisher.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)
SHA = 'a' * 40
NOW = '2026-10-18T07:00:00Z'


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def digest(value):
    return 'sha256:' + hashlib.sha256(value).hexdigest()


class RuntimePairTests(unittest.TestCase):
    """Atomic pairing of outputs already qualified by the existing per-image path."""
    def documents(self):
        docs = {}
        for component in ('php-fpm', 'nginx'):
            platforms = [dict(platform='linux/'+arch,
                              manifest_digest=digest((component+arch).encode()),
                              config_digest=digest((component+arch+'config').encode()),
                              labels={'secpal.dev/runtime-component': component})
                         for arch in ('amd64', 'arm64')]
            scans = [dict(platform=p['platform'], scanner=scanner, status='SUCCEEDED',
                          policy_result='CLEAN') for p in platforms for scanner in ('grype', 'trivy')]
            docs[component] = dict(
                source=dict(repository=publisher.SOURCE, commit=SHA, runtime=component),
                workflow=dict(repository='SecPal/api', path='.github/workflows/publish-container.yml',
                              commit=SHA, run_id='123', run_attempt='1'),
                subject=dict(repository=publisher.REPOSITORY, index_digest=digest(component.encode()),
                             platforms=platforms),
                generated_at=NOW, scans=scans,
                sboms=[dict(platform=p['platform'], subject_digest=p['manifest_digest']) for p in platforms],
                smoke_tests=[dict(platform=p['platform'], digest=p['manifest_digest'], result='SUCCEEDED')
                             for p in platforms],
                extensions={publisher.SOURCE: {}})
        for document in docs.values():
            for smoke in document['smoke_tests']:
                smoke['runtime_pair'] = {
                    component:next(p['manifest_digest'] for p in other['subject']['platforms']
                                   if p['platform'] == smoke['platform'])
                    for component,other in docs.items()
                }
        return docs

    def assemble(self, docs):
        return publisher.assemble_runtime_pair(docs, SHA, NOW)

    def test_complete_same_source_pair_has_two_digest_only_images_and_evidence(self):
        docs = self.documents()
        pair = self.assemble(docs)
        self.assertEqual(pair['source']['commit'], SHA)
        self.assertEqual(set(pair['images']), {'php-fpm', 'nginx'})
        for component in pair['images']:
            self.assertEqual(pair['images'][component]['reference'],
                             publisher.REPOSITORY + '@' + docs[component]['subject']['index_digest'])
            self.assertEqual(pair['images'][component]['build_evidence_sha256'],
                             digest(encoded(docs[component]) + b'\n'))

    def test_missing_extra_or_substituted_component_is_rejected(self):
        docs = self.documents()
        for value in ({'php-fpm': docs['php-fpm']},
                      {**docs, 'legacy': docs['php-fpm']},
                      {'php-fpm': docs['nginx'], 'nginx': docs['php-fpm']}):
            with self.subTest(value=list(value)), self.assertRaises(ValueError):
                self.assemble(value)

    def test_different_source_run_or_platform_set_is_rejected(self):
        for field in ('commit', 'run', 'attempt', 'platform', 'index'):
            docs = self.documents()
            target = docs['nginx']
            if field == 'commit':
                target['source']['commit'] = 'f' * 40
            elif field == 'run':
                target['workflow']['run_id'] = '999'
            elif field == 'attempt':
                target['workflow']['run_attempt'] = '999'
            elif field == 'platform':
                target['subject']['platforms'].pop()
            else:
                target['subject']['index_digest'] = docs['php-fpm']['subject']['index_digest']
            with self.subTest(field=field), self.assertRaises(ValueError):
                self.assemble(docs)

    def test_nonclean_incomplete_or_exceptional_security_is_rejected(self):
        for change in ('ACTIONABLE', 'REVIEW_REQUIRED', 'FAILED', 'missing', 'exception'):
            docs = self.documents()
            target = docs['nginx']
            if change in ('ACTIONABLE', 'REVIEW_REQUIRED'):
                target['scans'][0]['policy_result'] = change
            elif change == 'FAILED':
                target['scans'][0]['status'] = change
            elif change == 'missing':
                target['scans'].pop()
            else:
                target['extensions'][publisher.SOURCE]['artifact_security_admission'] = {'admission': 'ADMITTED_WITH_KNOWN_FINDINGS'}
            with self.subTest(change=change), self.assertRaises(ValueError):
                self.assemble(docs)

    def test_smoke_using_another_companion_is_rejected(self):
        docs = self.documents()
        docs['php-fpm']['smoke_tests'][0]['runtime_pair']['nginx'] = 'sha256:'+'f'*64
        with self.assertRaisesRegex(ValueError, 'smoke-companions'):
            self.assemble(docs)

    def test_failed_smoke_or_wrong_component_label_is_rejected(self):
        for field in ('smoke', 'label'):
            docs = self.documents()
            target = docs['nginx']
            if field == 'smoke':
                target['smoke_tests'][0]['result'] = 'FAILED'
            else:
                target['subject']['platforms'][0]['labels']['secpal.dev/runtime-component'] = 'php-fpm'
            with self.subTest(field=field), self.assertRaises(ValueError):
                self.assemble(docs)



    def test_local_layout_rejects_substituted_or_missing_layer_bytes(self):
        source = dict(commit=SHA, epoch=1700000000, runtime='php-fpm')
        base = [dict(stage='extensions',platform='linux/amd64',
                     reference='docker.io/library/php@sha256:'+'a'*64,digest='sha256:'+'b'*64)]
        config = encoded(dict(created=publisher.timestamp(source['epoch']),os='linux',architecture='amd64',
                              config=dict(User='secpal',Labels=publisher.expected_labels(source,base[0]))))
        layer = b'exact OCI layer bytes'
        manifest = encoded(dict(schemaVersion=2,mediaType=publisher.IMAGE,
            config=dict(mediaType='application/vnd.oci.image.config.v1+json',digest=digest(config),size=len(config)),
            layers=[dict(mediaType='application/vnd.oci.image.layer.v1.tar',digest=digest(layer),size=len(layer))]))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); blobs=root/'blobs/sha256';blobs.mkdir(parents=True)
            publisher.write(root/'oci-layout',dict(imageLayoutVersion='1.0.0'))
            publisher.write(root/'index.json',dict(schemaVersion=2,manifests=[
                dict(mediaType=publisher.IMAGE,digest=digest(manifest),size=len(manifest))]))
            for raw in (config,layer,manifest):
                (blobs/digest(raw).split(':')[1]).write_bytes(raw)
            publisher.read_layout(root,'amd64',source,base)
            target=blobs/digest(layer).split(':')[1]
            target.write_bytes(b'substituted OCI bytes')
            with self.assertRaisesRegex(ValueError,'publisher-layer'):
                publisher.read_layout(root,'amd64',source,base)
            target.unlink()
            with self.assertRaises(ValueError):
                publisher.read_layout(root,'amd64',source,base)

    def test_missing_qualified_bootstrap_cleans_owned_temporary_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);temporary=root/'temporary';temporary.mkdir()
            env=dict(os.environ,RUNTIME_SET='pair',PHP_FPM_IMAGE='never-created',
                     NGINX_IMAGE='never-created',GITHUB_WORKSPACE=str(root/'missing-checkout'),
                     TMPDIR=str(temporary))
            script=Path(__file__).resolve().parents[1]/'tests/docker/smoke.sh'
            result=subprocess.run(['sh',str(script)],env=env,capture_output=True,text=True)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(list(temporary.iterdir()),[])

    def test_pair_smoke_reaches_tls_setup_without_an_undefined_command(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            tools = root/'bin'; tools.mkdir()
            contracts = root/'.context/runtime-contracts/scripts'; contracts.mkdir(parents=True)
            (contracts/'production-secret-bootstrap.php').write_text('fixture')
            commands = {
                'git': 'echo bf2282e3e112ac003360ecb3dff390eafe282dbd',
                'php': 'echo postgres.internal',
                'podman': 'case "$*" in *Architecture*) echo amd64;; esac; exit 0',
                'openssl': 'if [ "$1" = rand ]; then echo synthetic; else echo reached-tls-setup >&2; exit 78; fi',
            }
            for name, body in commands.items():
                path = tools/name
                path.write_text('#!/bin/sh\n'+body+'\n'); path.chmod(0o755)
            scripts = root/'tests/docker'; scripts.mkdir(parents=True)
            (scripts/'runtime-component.sh').write_text('#!/bin/sh\nexit 0\n')
            (scripts/'runtime-component.sh').chmod(0o755)
            (scripts/'assert-port-closed.php').write_text('fixture')
            (root/'database').mkdir()
            env = dict(os.environ, PATH=str(tools)+':'+os.environ['PATH'],
                       RUNTIME_SET='pair', PHP_FPM_IMAGE='fixture', NGINX_IMAGE='fixture',
                       SKIP_BUILD='1', EXPECTED_ARCH='amd64', GITHUB_WORKSPACE=str(root))
            script = Path(__file__).resolve().parents[1]/'tests/docker/smoke.sh'
            result = subprocess.run(['sh', str(script)], env=env, cwd=root, capture_output=True, text=True)
            self.assertEqual(result.returncode, 78)
            self.assertNotIn('app_podman', result.stderr)

    def test_read_only_php_cli_supplies_its_writable_runtime_directories(self):
        script = (Path(__file__).resolve().parents[1]/'tests/docker/smoke.sh').read_text()
        body = script[script.index('run_api() {'):script.index('\nif [ "$runtime_set" = pair ]; then\n    podman exec -i')]
        setup = (
            'runtime_set=pair; network=fixture; api_env=fixture; secret_root=fixture; '
            'bootstrap_source=fixture; bootstrap_ini=fixture; image=fixture; '
            'podman() { printf "%s\\n" "$@"; };\n'
        )
        result = subprocess.run(['sh', '-c', setup+body+'\nrun_api php artisan tinker --execute=fixture'],
                                capture_output=True, text=True, check=True)
        args = result.stdout.splitlines()
        self.assertIn('--read-only', args)
        for directory in ('/tmp', '/config', '/data', '/app/storage', '/app/bootstrap/cache'):
            self.assertTrue(any(v.startswith('type=tmpfs,destination='+directory+',')
                                and 'chown=true' in v for v in args), directory)

    def test_unpublished_pair_observes_four_images_and_rejects_incomplete_or_nonclean_inputs(self):
        for change in (None, 'missing', 'companion', 'source', 'policy', 'raw', 'sbom', 'run'):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as directory:
                root = Path(directory); native = root/'native'; scans = root/'scan'
                output = root/'pair.json'; output.write_text('stale')
                records = {}
                docs = self.documents()
                for arch in ('amd64', 'arm64'):
                    for role in publisher.RUNTIME_COMPONENTS:
                        own = native/f'api-native-{arch}-123-1'/role; own.mkdir(parents=True)
                        folder = scans/role/arch; (folder/'sbom').mkdir(parents=True)
                        (folder/'raw').mkdir()
                        subject = next(p for p in docs[role]['subject']['platforms'] if p['platform'] == 'linux/'+arch)
                        expected = dict(repository=publisher.SOURCE, commit=SHA, runtime=role)
                        records[(arch, role)] = dict(source=expected, subject=subject)
                        spdx = b'fixture SPDX'
                        (folder/'sbom/syft.spdx.json').write_bytes(spdx)
                        target = dict(platform='linux/'+arch, oci_digest=subject['manifest_digest'])
                        publisher.write(folder/'sbom/association.json', dict(subject=target, spdx_digest=digest(spdx)))
                        publisher.write(folder/'evaluation.json', dict(subject=target))
                        smoke = next(v for v in docs[role]['smoke_tests'] if v['platform'] == 'linux/'+arch)
                        smoke.update(runner_architecture=arch, test='tests/docker/smoke.sh',
                                     workflow_run_id='123', workflow_run_attempt='1', completed_at=NOW)
                        publisher.write(own/'smoke.json', smoke)
                        scans_record = []
                        for scanner in ('grype', 'trivy'):
                            raw = b'fixture raw '+scanner.encode()
                            (folder/'raw'/(scanner+'.json')).write_bytes(raw)
                            metadata = dict(scanner_identity=dict(version='1.2.3'),
                                            database=dict(identity='fixture'), completed_at=NOW, status='SUCCEEDED')
                            publisher.write(folder/(scanner+'-metadata.json'), metadata)
                            scans_record.append(dict(platform='linux/'+arch, scanner=scanner, scanner_version='1.2.3',
                                database=metadata['database'], completed_at=NOW, status='SUCCEEDED', policy_result='CLEAN',
                                evidence_sha256=digest(raw), subject_digest=digest(spdx) if scanner == 'grype' else target['oci_digest']))
                        publisher.write(folder/'scan-records.json', scans_record)
                selected = native/'api-native-amd64-123-1/nginx/smoke.json'
                folder = scans/'nginx/amd64'
                if change == 'missing':
                    selected.unlink()
                elif change in ('companion', 'run'):
                    value = publisher.read(selected)
                    if change == 'companion':
                        value['runtime_pair']['php-fpm'] = 'sha256:'+'f'*64
                    else:
                        value['workflow_run_id'] = '999'
                    publisher.write(selected, value)
                elif change == 'source':
                    records[('amd64', 'nginx')]['source']['commit'] = 'f'*40
                elif change == 'raw':
                    (folder/'raw/grype.json').write_bytes(b'substituted')
                elif change == 'sbom':
                    (folder/'sbom/syft.spdx.json').write_bytes(b'substituted')
                policy = dict(evidence_health=dict(state='HEALTHY'), gate_state='ACTIONABLE' if change == 'policy' else 'CLEAN')
                def observe(_root, own, arch, expected):
                    self.assertEqual(own, native/f'api-native-{arch}-123-1'/expected['runtime'])
                    return records[(arch, expected['runtime'])], b'manifest', b'config'
                with mock.patch.dict(os.environ, GITHUB_SHA=SHA, GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='1'), \
                     mock.patch.object(publisher, 'source', side_effect=lambda _r, sha, role: dict(repository=publisher.SOURCE, commit=sha, runtime=role)), \
                     mock.patch.object(publisher, 'observe_native', side_effect=observe) as observation, \
                     mock.patch.object(publisher, 'observe_policy', return_value=policy), \
                     mock.patch.object(publisher, 'evidence_now', return_value=NOW), \
                     mock.patch.object(publisher, 'run', return_value=b'fixture-tree'):
                    if change is None:
                        publisher.qualify_unpublished_pair(root, native, scans, output)
                        value = publisher.read(output)
                        self.assertFalse(value['published'])
                        self.assertEqual(value['source']['commit'], SHA)
                        self.assertEqual(value['qualification'], 'CLEAN')
                        self.assertEqual(observation.call_count, 4)
                        self.assertEqual(len(value['images']['nginx']['platforms']), 2)
                    else:
                        with self.assertRaises((ValueError, OSError)):
                            publisher.qualify_unpublished_pair(root, native, scans, output)
                        self.assertFalse(output.exists())

    def test_second_component_failure_removes_stale_pair(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            output = root/'runtime-pair.json'
            output.write_text('stale acceptance')
            def complete(own, scan, smoke, repository, destination):
                if own.name == 'nginx':
                    raise ValueError('second component rejected')
                own.mkdir()
                publisher.write(destination, self.documents()['php-fpm'])
            with mock.patch.object(publisher, 'complete', side_effect=complete):
                with self.assertRaisesRegex(ValueError, 'second component rejected'):
                    publisher.complete_runtime_pair(root, root, root, root, output)
            self.assertFalse(output.exists())

if __name__ == '__main__':
    unittest.main()
