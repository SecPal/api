#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""API publisher admission fixtures, not native build or registry evidence."""
import copy
import hashlib
import importlib.util
import json
import os
import shlex
from pathlib import Path
import unittest
import subprocess
import tempfile
import sys
sys.dont_write_bytecode = True

spec=importlib.util.spec_from_file_location('publisher',Path(__file__).resolve().parents[1]/'scripts/container-publisher.py')
publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
SHA='a'*40
SOURCE=dict(repository='https://github.com/SecPal/api',commit=SHA,epoch=1700000000,context=dict(path='.'),
            containerfile=dict(path='Containerfile',sha256='sha256:'+'b'*64),
            containerignore=dict(path='.containerignore',sha256='sha256:'+'c'*64))
NOW='2026-10-18T07:00:00Z'


def encoded(value):
    return json.dumps(value,sort_keys=True,separators=(',',':')).encode()


def digest(value):
    return 'sha256:'+hashlib.sha256(value).hexdigest()


def platform(arch):
    base=[dict(stage=s,platform='linux/'+arch,reference='docker.io/'+name+':1@sha256:'+'d'*64,
               digest='sha256:'+'e'*64) for s,name in [('composer','library/composer'),('extensions','dunglas/frankenphp')]]
    labels={
        'org.opencontainers.image.source':'https://github.com/SecPal/api',
        'org.opencontainers.image.revision':SHA,
        'org.opencontainers.image.created':'2023-11-14T22:13:20Z',
        'org.opencontainers.image.licenses':'AGPL-3.0-or-later',
        'org.opencontainers.image.base.name':base[1]['reference'],
        'org.opencontainers.image.base.digest':base[1]['digest'],
        'org.opencontainers.image.title':'SecPal API',
        'org.opencontainers.image.description':'Production API for SecPal operations software',
    }
    config=encoded(dict(created='2023-11-14T22:13:20Z',os='linux',architecture=arch,config=dict(User='secpal',Labels=labels)))
    manifest=encoded(dict(schemaVersion=2,mediaType='application/vnd.oci.image.manifest.v1+json',
                         config=dict(mediaType='application/vnd.oci.image.config.v1+json',
                                     digest=digest(config),size=len(config)),layers=[]))
    descriptor=dict(mediaType='application/vnd.oci.image.manifest.v1+json',digest=digest(manifest),size=len(manifest))
    subject=publisher.admit_platform(manifest,config,descriptor,arch,SOURCE,base)
    return base,config,manifest,descriptor,subject


class AttestationEvidenceFilenameTests(unittest.TestCase):
    def test_exact_digest_maps_to_portable_unique_filename(self):
        first = "sha256:" + "a" * 64
        second = "sha256:" + "b" * 64
        self.assertEqual(publisher.attestation_filename(first),
                         "attestation-sha256-" + "a" * 64 + ".json")
        self.assertNotEqual(publisher.attestation_filename(first),
                            publisher.attestation_filename(second))
        self.assertEqual(Path(publisher.attestation_filename(first)).name,
                         publisher.attestation_filename(first))
        self.assertNotRegex(publisher.attestation_filename(first), r'[":<>|*?\\/]')

    def test_scan_verifies_the_same_portable_file_with_original_digest(self):
        root = Path(__file__).resolve().parents[1]
        scan = (root / "scripts/scan-container-artifact.sh").read_text()
        self.assertIn('attestation-filename --digest "${subject##*@}"', scan)
        self.assertEqual(scan.count('$output/raw/${attestation_file}'), 2)
        self.assertIn('--digest "${subject##*@}"', scan)
        self.assertNotIn('attestation-${subject##*@}.json', scan)
        workflow = (root / ".github/workflows/publish-container.yml").read_text()
        # Existing always-path retention remains independent of scan acceptance.
        self.assertIn("if: always()", workflow)
        self.assertIn("retention-days:", workflow)

    def test_invalid_security_identity_never_becomes_a_path(self):
        for value in ("", "sha256:abc", "sha256:" + "A"*64, "sha512:" + "a"*64,
                      "../sha256:" + "a"*64, "sha256:" + "a"*64 + "/secret",
                      "sha256:" + "a"*64 + "\n"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                publisher.attestation_filename(value)


def evidence():
    records=[];sboms=[];scans=[];smokes=[]
    for arch in ['amd64','arm64']:
        base,config,manifest,descriptor,subject=platform(arch)
        records.append(dict(source=copy.deepcopy(SOURCE),subject=subject,bases=base,
                            builder=dict(name='buildah',version='1.43.2'),runner_architecture=arch,
                            workflow_run_id='42',workflow_run_attempt='2'))
        sboms.append(dict(platform='linux/'+arch,subject_digest=subject['manifest_digest'],
                          artifact='ghcr.io/secpal/api@sha256:'+'f'*64,
                          spdx_sha256='sha256:'+'1'*64,syft_version='1.54.1'))
        for scanner,version in [('grype','0.118.0'),('trivy','0.74.0')]:
            scans.append(dict(platform='linux/'+arch,scanner=scanner,scanner_version=version,
                              database=dict(identity='native-db:sha256:'+'2'*64,generated_at=NOW),
                              subject_digest=sboms[-1]['spdx_sha256'] if scanner=='grype' else subject['manifest_digest'],
                              completed_at=NOW,status='SUCCEEDED',policy_result='CLEAN',evidence_sha256='sha256:'+'3'*64))
        smokes.append(dict(platform='linux/'+arch,digest=subject['manifest_digest'],runner_architecture=arch,
                           test='tests/docker/smoke.sh',result='SUCCEEDED',completed_at=NOW,
                           workflow_run_id='42',workflow_run_attempt='2'))
    security=dict(schema_version='secpal-trivy-repository-scan-v2',subject=dict(repository='SecPal/api',commit=SHA),
                  gate_state='CLEAN',operation=dict(name='TRIVY_REPOSITORY_SCAN',status='SUCCEEDED'),
                  database=dict(status='FRESH',updated_at='2026-10-18T00:00:00Z',next_update='2026-10-19T00:00:00Z'))
    return [records,'sha256:'+'4'*64,sboms,scans,smokes,security,'42','2',NOW]


class Publisher(unittest.TestCase):
    def test_native_architecture_is_not_a_requested_platform_string(self):
        self.assertEqual(publisher.native_architecture('amd64','x86_64','X64'),'amd64')
        self.assertEqual(publisher.native_architecture('arm64','aarch64','ARM64'),'arm64')
        for args in [('amd64','aarch64','X64'),('arm64','x86_64','ARM64'),('amd64','x86_64','ARM64')]:
            with self.assertRaises(ValueError):publisher.native_architecture(*args)

    def test_actual_oci_bytes_config_labels_and_nonroot_identity(self):
        base,config,manifest,descriptor,subject=platform('amd64')
        for key,value in [('architecture','arm64'),('os','windows')]:
            candidate=json.loads(config);candidate[key]=value
            with self.assertRaises(ValueError):publisher.admit_platform(manifest,encoded(candidate),descriptor,'amd64',SOURCE,base)
        for key,value in [('User','root'),('Labels',{})]:
            candidate=json.loads(config);candidate['config'][key]=value
            cfg=encoded(candidate);image=json.loads(manifest);image['config'].update(digest=digest(cfg),size=len(cfg))
            raw=encoded(image);desc=dict(descriptor,digest=digest(raw),size=len(raw))
            with self.assertRaises(ValueError):publisher.admit_platform(raw,cfg,desc,'amd64',SOURCE,base)
        with self.assertRaises(ValueError):publisher.admit_platform(manifest+b' ',config,descriptor,'amd64',SOURCE,base)
        with self.assertRaises(ValueError):publisher.admit_platform(manifest,config,dict(descriptor,size=1),'amd64',SOURCE,base)
        self.assertEqual(subject['config_digest'],digest(config))

    def test_exact_complete_index_and_no_duplicate_architecture(self):
        a,b=platform('amd64'),platform('arm64')
        index=publisher.assemble_index([a[4],b[4]],[len(a[2]),len(b[2])])
        self.assertEqual(index['mediaType'],'application/vnd.oci.image.index.v1+json')
        self.assertEqual([p['platform']['architecture'] for p in index['manifests']],['amd64','arm64'])
        for records in ([a[4]],[a[4],a[4]],[b[4],a[4]]):
            with self.assertRaises(ValueError):publisher.assemble_index(records,[1]*len(records))

    def test_exact_source_security_is_required(self):
        args=evidence()
        self.assertEqual(publisher.admit_source_security(args[5],SHA,NOW)['gate_state'],'CLEAN')
        for state in ['UNKNOWN_STALE','DATABASE_FAILURE','REVIEW_REQUIRED','ACTIONABLE',None]:
            with self.assertRaises(ValueError):
                publisher.admit_source_security(dict(args[5],gate_state=state),SHA,NOW)
        with self.assertRaises(ValueError):publisher.admit_source_security(args[5],'0'*40,NOW)

    def test_actual_creation_time_and_source_scan_deadline(self):
        base,config,manifest,descriptor,subject=platform('amd64')
        candidate=json.loads(config);candidate['created']='2020-01-01T00:00:00Z'
        cfg=encoded(candidate);image=json.loads(manifest);image['config'].update(digest=digest(cfg),size=len(cfg))
        raw=encoded(image);desc=dict(descriptor,digest=digest(raw),size=len(raw))
        with self.assertRaises(ValueError):publisher.admit_platform(raw,cfg,desc,'amd64',SOURCE,base)
        security=evidence()[5]
        with self.assertRaises(ValueError):publisher.admit_source_security(security,SHA,'2026-10-20T00:00:00Z')
        with self.assertRaises(ValueError):publisher.admit_source_security(security,SHA,'2026-10-17T00:00:00Z')

    def test_build_evidence_binds_source_attempt_sbom_scanners_and_native_smoke(self):
        args=evidence()
        valid=publisher.build_evidence(*args,expected_source=SOURCE)
        self.assertEqual(valid['schema_version'],'1.0.0')
        self.assertEqual(len(valid['scans']),4)
        self.assertEqual(valid['workflow']['run_attempt'],'2')
        mutations=[
            lambda a:a[0][0].update(runner_architecture='arm64'),
            lambda a:a[0][0].update(workflow_run_attempt='1'),
            lambda a:a[2][0].update(artifact='ghcr.io/other/api@sha256:'+'f'*64),
            lambda a:a[3][0].update(subject_digest='sha256:'+'f'*64),
            lambda a:a[3][0].update(policy_result='UNKNOWN_STALE'),
            lambda a:a[4][0].update(result='FAILED'),
            lambda a:a[4][0].update(test='unrelated-probe'),
            lambda a:a[4][0].update(workflow_run_id='1'),
            lambda a:a[4].pop(),
        ]
        for mutate in mutations:
            candidate=copy.deepcopy(args);mutate(candidate)
            with self.assertRaises(ValueError):publisher.build_evidence(*candidate,expected_source=SOURCE)
        with self.assertRaises(ValueError):
            publisher.build_evidence(*args,expected_source=dict(SOURCE,commit='0'*40))

    def test_canonical_source_schema_rejects_malformed_clean_without_echoing_payload(self):
        root=Path(__file__).resolve().parents[1]
        script=(root/'scripts/publish-container.sh').read_text()
        start=script.index('import json,sys\nfrom jsonschema')
        block=script[start:script.index('\nPY\n',start)]
        schema=root/'.context/publisher-governance/docs/schemas/secpal-trivy-repository-scan-v2.schema.json'
        marker='SECPAL_SYNTHETIC_UNTRUSTED_FIELD'
        with tempfile.TemporaryDirectory() as directory:
            candidate=Path(directory)/'result.json'
            candidate.write_text(json.dumps(dict(gate_state='CLEAN',unexpected=marker)))
            result=subprocess.run([sys.executable,'-c',block,str(schema),str(candidate)],
                                  capture_output=True,text=True,check=False)
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(result.stderr.strip(),'FAIL: malformed source-security evidence')
        self.assertNotIn(marker,result.stdout+result.stderr)

    def test_startup_polling_does_not_run_expensive_failure_diagnostics(self):
        root=Path(__file__).resolve().parents[1]
        script=(root/'tests/docker/smoke.sh').read_text()
        block=script[script.index('assert_http() {'):script.index('wait_for_http() {')]
        with tempfile.TemporaryDirectory() as directory:
            calls=Path(directory)/'calls'
            shell='podman() { echo call >> "$CALLS"; return 1; }\n'+block+'\nassert_http /health/live 200 alive 0'
            result=subprocess.run(['sh','-c',shell],env=dict(os.environ,CALLS=str(calls),client='client',api='api',postgres='postgres'),
                                  capture_output=True,text=True,check=False)
            count=len(calls.read_text().splitlines())
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(count,1)

    def test_postgresql_fixture_waits_for_tcp_before_migrations(self):
        root=Path(__file__).resolve().parents[1]
        script=(root/'tests/docker/smoke.sh').read_text()
        start=script.index('attempt=0\nuntil podman exec "$postgres" pg_isready')
        block=script[start:script.index('\nrun_api() {',start)]
        with tempfile.TemporaryDirectory() as directory:
            count=Path(directory)/'calls'
            shell="""podman() {
                calls=$(cat "$COUNT" 2>/dev/null || echo 0)
                calls=$((calls+1)); printf '%s' "$calls" > "$COUNT"
                case " $* " in
                    *" -h 127.0.0.1 "*) test "$calls" -ge 2 ;;
                    *) return 0 ;;
                esac
            }
            sleep() { :; }
            """+block
            result=subprocess.run(['sh','-c',shell],env=dict(os.environ,COUNT=str(count),postgres='fixture'),
                                  capture_output=True,text=True,timeout=2,check=False)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(count.read_text(),'2')

    def test_registry_copy_arguments_execute_with_pinned_oras(self):
        root=Path(__file__).resolve().parents[1]
        oras=Path(os.environ['SECPAL_TEST_ORAS']).resolve()
        script=(root/'scripts/publish-container.sh').read_text()
        commands=[shlex.split(line.strip().rstrip(chr(92)).strip())[2:]
                  for line in script.splitlines() if '"$tools/oras" cp ' in line]
        self.assertTrue(commands)
        with tempfile.TemporaryDirectory() as directory:
            work=Path(directory)
            (work/'payload.txt').write_text('non-secret local CLI conformance probe\n')
            auth=work/'auth.json';auth.write_text('{"auths":{}}\n');auth.chmod(0o600)
            source=work/'source'
            subprocess.run([str(oras),'push','--oci-layout','--artifact-type',
                            'application/vnd.secpal.cli-probe.v1',str(source)+':probe',
                            'payload.txt:text/plain'],cwd=work,capture_output=True,text=True,check=True)
            original=json.loads((source/'index.json').read_text())['manifests'][0]['digest']
            for index,arguments in enumerate(commands):
                destination=work/('copy-'+str(index))
                actual=[str(auth) if value=='$REGISTRY_AUTH_FILE' else value for value in arguments]
                result=subprocess.run([str(oras),'cp',*actual,'--to-oci-layout',
                                       str(source)+':probe',str(destination)+':probe'],
                                      cwd=work,capture_output=True,text=True,check=False)
                self.assertEqual(result.returncode,0,result.stderr)
                observed=json.loads((destination/'index.json').read_text())['manifests'][0]['digest']
                self.assertEqual(observed,original)

    def test_missing_or_malformed_required_evidence_does_not_become_success(self):
        for collection,key,value in [(2,'spdx_sha256','not-a-digest'),(2,'syft_version','moving'),
                                      (3,'evidence_sha256','missing'),(3,'scanner_version','unknown'),
                                      (4,'completed_at','bad-time')]:
            args=evidence();args[collection][0][key]=value
            with self.assertRaises(ValueError):publisher.build_evidence(*args,expected_source=SOURCE)


if __name__=='__main__':
    unittest.main()
