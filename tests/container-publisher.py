#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""API publisher admission fixtures, not native build or registry evidence."""
import copy
from datetime import datetime, timedelta
import hashlib
import http.server
import importlib.util
import json
import os
import shlex
from pathlib import Path
import unittest
from unittest.mock import patch
import subprocess
import tempfile
import threading
import sys
sys.dont_write_bytecode = True

spec=importlib.util.spec_from_file_location('publisher',Path(__file__).resolve().parents[1]/'scripts/container-publisher.py')
publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
ROOT = Path(__file__).resolve().parents[1]
GOVERNANCE = ROOT / '.context/publisher-governance'
SOURCE_SCHEMA = json.loads((GOVERNANCE / 'docs/schemas/secpal-trivy-repository-scan-v4.schema.json').read_bytes())
scan_spec = importlib.util.spec_from_file_location('trusted_source_scanner', GOVERNANCE / 'scripts/secpal-trivy-repository-scan.py')
source_scanner = importlib.util.module_from_spec(scan_spec); scan_spec.loader.exec_module(source_scanner)
SOURCE_POLICY = json.loads((GOVERNANCE / 'policies/trivy-repository-scan-v1.json').read_bytes())

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


def source_security():
    # Contract fixture derives identities from the accepted shared implementation.
    return dict(
        schema_version=SOURCE_SCHEMA['properties']['schema_version']['const'],
        subject=dict(repository='SecPal/api', commit=SHA), completed_at=NOW,
        scanner=dict(name='trivy', version=source_scanner.TRIVY_VERSION,
                     immutable_id=source_scanner.TRIVY_ARCHIVE_ID,
                     configuration_sha256=source_scanner.configuration_identity()),
        policy=dict(id=source_scanner.POLICY_ID, version=SOURCE_POLICY['version'],
                    sha256=source_scanner._sha256(SOURCE_POLICY)),
        gate_state='CLEAN', operation=dict(name='TRIVY_REPOSITORY_SCAN', status='SUCCEEDED'),
        database=dict(status='FRESH', identity='sha256' + ':' + '5'*64,
                      updated_at='2026-10-18T00:00:00Z', next_update='2026-10-19T00:00:00Z',
                      downloaded_at=NOW),
        summary=dict(total=0, actionable=0, review_required=0, excepted=0), findings=[],
    )


def evidence():
    records=[];sboms=[];scans=[];smokes=[]
    for arch in ['amd64','arm64']:
        base,config,manifest,descriptor,subject=platform(arch)
        records.append(dict(source=copy.deepcopy(SOURCE),subject=subject,bases=base,
                            builder=dict(name='buildah',version='0.0.1'),runner_architecture=arch,
                            workflow_run_id='42',workflow_run_attempt='2'))
        sboms.append(dict(platform='linux/'+arch,subject_digest=subject['manifest_digest'],
                          artifact='ghcr.io/secpal/api@sha256:'+'f'*64,
                          spdx_sha256='sha256:'+'1'*64,syft_version='0.0.1'))
        for scanner,version in [('grype','0.0.1'),('trivy','0.0.1')]:
            scans.append(dict(platform='linux/'+arch,scanner=scanner,scanner_version=version,
                              database=dict(identity='native-db:sha256:'+'2'*64,generated_at=NOW),
                              subject_digest=sboms[-1]['spdx_sha256'] if scanner=='grype' else subject['manifest_digest'],
                              completed_at=NOW,status='SUCCEEDED',policy_result='CLEAN',evidence_sha256='sha256:'+'3'*64))
        smokes.append(dict(platform='linux/'+arch,digest=subject['manifest_digest'],runner_architecture=arch,
                           test='tests/docker/smoke.sh',result='SUCCEEDED',completed_at=NOW,
                           workflow_run_id='42',workflow_run_attempt='2'))
    security=source_security()
    return [records,'sha256:'+'4'*64,sboms,scans,smokes,security,'42','2',NOW]


class Publisher(unittest.TestCase):
    def test_utc_representations_preserve_real_grype_values_in_complete_evidence(self):
        args=evidence()
        completed='2026-10-19T21:41:00Z'
        args[8]=completed
        args[5]['completed_at']=completed
        args[5]['database'].update(updated_at='2026-10-19T00:00:00Z',next_update='2026-10-20T00:00:00Z')
        original=['2026-10-19T21:40:29.598833+00:00','2026-10-19T21:39:59.004353+00:00']
        for scan,value in zip([x for x in args[3] if x['scanner']=='grype'],original,strict=True):
            scan['completed_at']=value
        result=publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA)
        self.assertEqual([x['completed_at'] for x in result['scans'] if x['scanner']=='grype'],original)
        for value in original:
            self.assertEqual(publisher.evidence_time(value),publisher.evidence_time(value.replace('+00:00','Z')))
        self.assertTrue(all(x['completed_at'].endswith('Z') for x in result['scans'] if x['scanner']=='trivy'))

    def test_unapproved_ambiguous_or_future_evidence_time_fails_closed(self):
        for value in (None,1,[],{},'2026-10-19T21:40:29','2026-10-19T21:40:29+01:00',
                      '2026-10-19T21:40:29-00:00','2026-10-19 21:40:29Z','2026-10-19T21:40:29.Z',
                      '2026-10-19T21:40:29,1Z','2026-02-30T21:40:29Z','2026-10-19T25:40:29Z',
                      '2026-10-19T21:40:29+00:00Z','2026-10-19T21:40:29ZZ','2026-10-19T21:40:29Z trailing'):
            with self.subTest(value=value),self.assertRaisesRegex(ValueError,'publisher-evidence-time'):
                publisher.evidence_time(value)
        for scanner in ('grype','trivy'):
            args=evidence();next(x for x in args[3] if x['scanner']==scanner)['completed_at']='2026-10-18T07:00:00.000001+00:00'
            with self.assertRaisesRegex(ValueError,'publisher-scan-acceptance'):
                publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA)

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
        self.assertEqual(publisher.admit_source_security(args[5],SHA,NOW,schema=SOURCE_SCHEMA)['gate_state'],'CLEAN')
        for state in ['UNKNOWN_STALE','DATABASE_FAILURE','REVIEW_REQUIRED','ACTIONABLE',None]:
            with self.assertRaises(ValueError):
                publisher.admit_source_security(dict(args[5],gate_state=state),SHA,NOW,schema=SOURCE_SCHEMA)
        with self.assertRaises(ValueError):publisher.admit_source_security(args[5],'0'*40,NOW,schema=SOURCE_SCHEMA)

    def test_actual_creation_time_and_source_scan_deadline(self):
        base,config,manifest,descriptor,subject=platform('amd64')
        candidate=json.loads(config);candidate['created']='2020-01-01T00:00:00Z'
        cfg=encoded(candidate);image=json.loads(manifest);image['config'].update(digest=digest(cfg),size=len(cfg))
        raw=encoded(image);desc=dict(descriptor,digest=digest(raw),size=len(raw))
        with self.assertRaises(ValueError):publisher.admit_platform(raw,cfg,desc,'amd64',SOURCE,base)
        security=evidence()[5]
        with self.assertRaises(ValueError):publisher.admit_source_security(security,SHA,'2026-10-20T00:00:00Z',schema=SOURCE_SCHEMA)
        with self.assertRaises(ValueError):publisher.admit_source_security(security,SHA,'2026-10-17T00:00:00Z',schema=SOURCE_SCHEMA)

    def test_build_evidence_binds_source_attempt_sbom_scanners_and_native_smoke(self):
        args=evidence()
        valid=publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA)
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
            with self.assertRaises(ValueError):publisher.build_evidence(*candidate,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA)
        with self.assertRaises(ValueError):
            publisher.build_evidence(*args,expected_source=dict(SOURCE,commit='0'*40),source_security_schema=SOURCE_SCHEMA)

    def test_v4_reader_preserves_the_complete_producer_identity(self):
        value=source_security()
        admitted=publisher.admit_source_security(value,SHA,NOW,schema=SOURCE_SCHEMA)
        self.assertIs(admitted,value)
        self.assertEqual(admitted['scanner'],value['scanner'])
        self.assertEqual(admitted['policy'],value['policy'])

    def test_v4_reader_rejects_malformed_wrong_schema_and_non_clean_records(self):
        mutations=[
            lambda v:v.update(schema_version='secpal-trivy-repository-scan-v2'),
            lambda v:v.update(schema_version='unknown'),
            lambda v:v.pop('scanner'),
            lambda v:v['scanner'].update(immutable_id='not-a-digest'),
            lambda v:v['policy'].pop('sha256'),
            lambda v:v.update(unexpected='SECPAL_SYNTHETIC_UNTRUSTED_FIELD'),
            lambda v:v['operation'].update(status='FAILED'),
            lambda v:v['subject'].update(repository='Other/api'),
            lambda v:v['database'].update(status='STALE'),
        ]
        for mutate in mutations:
            with self.subTest(mutation=mutate):
                value=source_security();mutate(value)
                with self.assertRaises(ValueError):
                    publisher.admit_source_security(value,SHA,NOW,schema=SOURCE_SCHEMA)
        for value in (None,[],42):
            with self.assertRaises(ValueError):
                publisher.admit_source_security(value,SHA,NOW,schema=SOURCE_SCHEMA)

    def test_v4_source_database_deadline_retains_its_exclusive_upper_bound(self):
        value=source_security()
        publisher.admit_source_security(value,SHA,value['database']['updated_at'],schema=SOURCE_SCHEMA)
        with self.assertRaises(ValueError):
            publisher.admit_source_security(value,SHA,value['database']['next_update'],schema=SOURCE_SCHEMA)

    def test_canonical_source_schema_rejects_malformed_clean_without_echoing_payload(self):
        marker='SECPAL_SYNTHETIC_UNTRUSTED_FIELD'
        with tempfile.TemporaryDirectory() as directory:
            candidate=Path(directory)/'result.json'
            candidate.write_text(json.dumps(dict(gate_state='CLEAN',unexpected=marker)))
            result=subprocess.run([
                sys.executable,str(ROOT/'scripts/container-publisher.py'),
                'source-security','--input',str(candidate),'--commit',SHA,
            ],capture_output=True,text=True,check=False)
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(result.stderr.strip(),'FAIL: publisher-source-security-schema')
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
            with self.assertRaises(ValueError):publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA)



class ExactArtifactAdmission(unittest.TestCase):
    def test_separate_admission_preserves_nonclean_scan_records(self):
        args=evidence()
        for scan in args[3]:scan['policy_result']='ACTIONABLE'
        admission=dict(admission='ADMITTED_WITH_KNOWN_FINDINGS',
                       source=dict(repository='SecPal/api',commit=SHA,tree='c'*40),
                       index_digest=args[1],expires_at='2026-10-18T08:00:00Z',
                       evidence_sha256='sha256:'+'9'*64,policy_results=[])
        with self.assertRaises(ValueError):
            publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA)
        result=publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA,
                                        artifact_admission=admission)
        self.assertEqual(result['scans'],args[3])
        self.assertTrue(all(r['policy_result']=='ACTIONABLE' for r in result['scans']))
        self.assertEqual(result['extensions'][publisher.SOURCE]['artifact_security_admission']['admission'],
                         'ADMITTED_WITH_KNOWN_FINDINGS')
        for key,value in [('admission','CLEAN'),('source',{}),('index_digest','sha256:'+'0'*64),
                          ('expires_at',NOW),('expires_at','2026-10-18T06:00:00Z')]:
            changed=dict(admission);changed[key]=value
            with self.subTest(key=key,value=value),self.assertRaises(ValueError):
                publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA,
                                         artifact_admission=changed)
        args[3][0]['policy_result']='UNKNOWN_STALE'
        with self.assertRaises(ValueError):
            publisher.build_evidence(*args,expected_source=SOURCE,source_security_schema=SOURCE_SCHEMA,
                                     artifact_admission=admission)


class CandidateContinuation(unittest.TestCase):
    def setUp(self):
        self.prepared='api-prepared-42-1'
        names=[self.prepared,'api-scans-42-1','api-smoke-amd64-42-1','api-smoke-arm64-42-1']
        required=['Prepare exact digest subjects','Attest candidate index platforms and Syft artifacts',
                  'Repository Security / Accept API Repository Security',
                  'Build native API candidates / Native API (amd64)',
                  'Build native API candidates / Native API (arm64)',
                  'Published native runtime (amd64)','Published native runtime (arm64)',
                  'Verify authoritative SPDX and independent artifact scans']
        self.artifacts=dict(total_count=len(names),artifacts=[
            dict(name=n,digest='sha256:'+'a'*64,expired=False) for n in names])
        self.origin=dict(id=42,run_attempt=1,head_sha=SHA,head_branch='main',event='push',
                         path='.github/workflows/publish-container.yml',repository=dict(full_name='SecPal/api'))
        self.commit=dict(sha=SHA,commit=dict(verification=dict(verified=True)))
        self.comparison=dict(base_commit=dict(sha='d'*40),merge_base_commit=dict(sha=SHA),ahead_by=0,status='behind')
        self.jobs=dict(total_count=len(required),jobs=[
            dict(name=n,status='completed',conclusion='success') for n in required])

    def api(self,path):
        if path.endswith('/artifacts?per_page=100'):return self.artifacts
        if path.endswith('/attempts/1'):return self.origin
        if path.endswith('/jobs?per_page=100'):return self.jobs
        if '/compare/' in path:return self.comparison
        if '/commits/' in path:return self.commit
        raise AssertionError('Unexpected external request')

    def test_retry_reuses_original_run_and_attempt_without_building_or_repackaging(self):
        result=publisher.candidate_context(SHA,'42','2',api=self.api)
        self.assertEqual(result,dict(source_commit=SHA,run_id='42',run_attempt='1',scan_attempt='1',execution_attempt='2'))
        self.assertEqual(publisher.candidate_context(SHA,'42','1',api=self.api)['run_attempt'],'1')
        self.origin['path']+="@main"
        self.assertEqual(publisher.candidate_context(SHA,'42','2',api=self.api)['run_attempt'],'1')

    def test_new_readonly_scan_can_authorize_the_same_original_artifact(self):
        self.artifacts['artifacts'][1]['name']='api-scans-42-2'
        result=publisher.candidate_context(SHA,'42','2',api=self.api)
        self.assertEqual(result['run_attempt'],'1')
        self.assertEqual(result['scan_attempt'],'2')
        self.jobs['jobs'][-1]['conclusion']='cancelled'
        with self.assertRaises(ValueError):
            publisher.candidate_context(SHA,'42','2',api=self.api)

    def test_missing_expired_or_ambiguous_original_artifacts_are_denied(self):
        for mutate in (
            lambda:self.artifacts['artifacts'].pop(),
            lambda:self.artifacts['artifacts'][0].update(expired=True),
            lambda:self.artifacts['artifacts'].append(dict(name='api-prepared-42-2',digest='sha256:'+'a'*64,expired=False)),
        ):
            self.setUp();mutate();self.artifacts['total_count']=len(self.artifacts['artifacts'])
            with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)

    def test_wrong_source_ref_workflow_repository_or_unaccepted_source_is_denied(self):
        for key,value in [('head_sha','b'*40),('head_branch','topic'),('event','workflow_dispatch'),
                          ('path','.github/workflows/other.yml'),('path','.github/workflows/publish-container.yml@topic'),('repository',dict(full_name='other/api'))]:
            self.setUp();self.origin[key]=value
            with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)
        self.setUp();self.commit['commit']['verification']['verified']=False
        with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)
        self.setUp();self.comparison.update(ahead_by=1,status='ahead')
        with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)
        self.setUp();self.comparison['merge_base_commit']['sha']='e'*40
        with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)

    def test_failed_skipped_cancelled_missing_and_duplicate_original_stages_are_denied(self):
        for state in ['failure','skipped','cancelled',None]:
            self.setUp();self.jobs['jobs'][0]['conclusion']=state
            with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)
        for change in ['missing','duplicate']:
            self.setUp()
            if change=='missing':self.jobs['jobs'].pop()
            else:self.jobs['jobs'].append(copy.deepcopy(self.jobs['jobs'][0]))
            self.jobs['total_count']=len(self.jobs['jobs'])
            with self.assertRaises(ValueError):publisher.candidate_context(SHA,'42','2',api=self.api)

    def test_only_existing_acceptance_job_gains_continuation_and_discovery_is_last(self):
        workflow=(ROOT/'.github/workflows/publish-container.yml').read_text()
        self.assertNotIn('workflow_dispatch',workflow)
        acceptance=workflow.split('  accept:\n',1)[1]
        self.assertIn('steps.candidate.outputs.candidate_attempt',acceptance)
        self.assertNotIn('build-container.sh',acceptance)
        self.assertNotIn('path: .context/admission-decision',acceptance)
        self.assertNotIn('.context/admission-decision/scripts/',acceptance)
        shell=(ROOT/'scripts/publish-container.sh').read_text()
        accept=shell.split('  accept)\n',1)[1]
        self.assertLess(accept.index('container-publisher.py admission'),accept.index('"$tools/oras" tag'))
        self.assertIn('candidate_attempt=$(jq -er .workflow.run_attempt',accept)


class EvidenceRetention(unittest.TestCase):
    def test_actual_retention_preserves_normal_and_exceptional_evidence_files(self):
        script=(ROOT/'scripts/publish-container.sh').read_text()
        start=script.index('    # The file attestation already exists')
        end=script.index('    evidence_digest=',start)
        block=script[start:end]
        normal=['build-evidence.json','source-security.json','build-evidence.bundle.json']
        extra=['artifact-risk-acceptance.json','artifact-security-evidence.json']
        for exceptional in (False,True):
            with self.subTest(exceptional=exceptional),tempfile.TemporaryDirectory() as directory:
                root=Path(directory);prepared=root/'prepared';prepared.mkdir()
                expected=normal+extra if exceptional else normal
                for name in expected:(prepared/name).write_bytes(encoded(dict(fixture=name)))
                tools=root/'tools';tools.mkdir()
                (tools/'oras').symlink_to(Path(os.environ['SECPAL_TEST_ORAS']).resolve())
                result=subprocess.run(['bash','-c','set -euo pipefail'+chr(10)+block],
                                      env=dict(os.environ,prepared=str(prepared),tools=str(tools),RUNNER_TEMP=str(root)),
                                      capture_output=True,text=True)
                self.assertEqual(result.returncode,0,result.stderr)
                manifest=json.loads((prepared/'build-evidence-manifest.json').read_bytes())
                titles=[layer.get('annotations',{}).get('org.opencontainers.image.title') for layer in manifest['layers']]
                self.assertEqual(set(titles),set(expected))
                for layer in manifest['layers']:
                    name=layer['annotations']['org.opencontainers.image.title']
                    blob=root/'build-evidence/blobs/sha256'/layer['digest'].split(':')[1]
                    self.assertEqual(blob.read_bytes(),(prepared/name).read_bytes())


class InterruptedContinuation(unittest.TestCase):
    def test_failed_final_admission_cannot_reach_discovery(self):
        script=(ROOT/'scripts/publish-container.sh').read_text()
        start=script.index('    # Reauthenticate current authority')
        end=script.index('    "$tools/oras" manifest fetch',start)
        block=script[start:end]
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);(root/'oras').write_text('#!/bin/sh\nprintf invoked > "$MARKER"\n')
            (root/'oras').chmod(0o755)
            env=dict(os.environ,MARKER=str(root/'discovery'),tools=str(root),repo_root='/fixture',
                     prepared='/fixture',GITHUB_SHA=SHA,GITHUB_RUN_ID='42')
            result=subprocess.run(['bash','-c','set -eu\npython3() { return 1; }\n'+block],
                                  env=env,capture_output=True,text=True)
            self.assertNotEqual(result.returncode,0)
            self.assertFalse((root/'discovery').exists())


class DiscoveryContinuation(unittest.TestCase):
    """Pinned CLI read-only replay; tag calls are captured, never published."""
    def test_existing_pointer_and_registry_failures_never_overwrite_discovery(self):
        script=(ROOT/'scripts/publish-container.sh').read_text()
        start=script.index('    # Existing pointers are verified')
        end=script.index('    "$tools/oras" manifest fetch --registry-config "$REGISTRY_AUTH_FILE" "$repository:$published_tag" > "$prepared/discovery-index.json"',start)
        block=script[start:end]
        expected=encoded(dict(schemaVersion=2,mediaType=publisher.INDEX,manifests=[]))
        for status,same,allowed,tagged in [(200,True,True,False),(200,False,False,False),
                                          (404,True,True,True),(401,True,False,False),(503,True,False,False)]:
            with self.subTest(status=status,same=same),tempfile.TemporaryDirectory() as directory:
                root=Path(directory)
                class Registry(http.server.BaseHTTPRequestHandler):
                    def log_message(self,*args):pass
                    def do_HEAD(self):self.serve(False)
                    def do_GET(self):self.serve(True)
                    def serve(self,body):
                        if self.path=='/v2/':code=200;payload=b'{}';media='application/json'
                        else:
                            code=status;media=publisher.INDEX if code==200 else 'application/json'
                            payload=(expected if same else encoded(dict(schemaVersion=1))) if code==200 else encoded(
                                dict(errors=[dict(code='MANIFEST_UNKNOWN' if code==404 else 'UNAVAILABLE',message='fixture')]))
                        self.send_response(code);self.send_header('Content-Type',media)
                        self.send_header('Content-Length',str(len(payload)));self.send_header('Docker-Content-Digest',digest(payload));self.end_headers()
                        if body:self.wfile.write(payload)
                server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Registry)
                thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
                try:
                    (root/'index.json').write_bytes(expected);(root/'auth.json').write_text('{"auths":{}}')
                    (root/'oras').write_text('#!/bin/sh\nif [ "$1" = tag ]; then : > "$MARKER"; else exec "$PINNED_ORAS" "$@" --plain-http; fi\n')
                    (root/'oras').chmod(0o755)
                    env=dict(os.environ,tools=str(root),prepared=str(root),RUNNER_TEMP=str(root),
                             REGISTRY_AUTH_FILE=str(root/'auth.json'),DOCKER_CONFIG=str(root),
                             repository=f'127.0.0.1:{server.server_address[1]}/fixture/api',published_tag='original-run',
                             index_digest=digest(expected),MARKER=str(root/'tag-called'),
                             PINNED_ORAS=os.environ['SECPAL_TEST_ORAS'])
                    result=subprocess.run(['bash','-c','set -eu\n'+block],env=env,capture_output=True,text=True,timeout=20)
                    self.assertEqual(result.returncode==0,allowed,result.stderr)
                    self.assertEqual((root/'tag-called').exists(),tagged)
                finally:server.shutdown();server.server_close();thread.join()


class ArtifactScanCollection(unittest.TestCase):
    def test_both_platform_policy_verdicts_are_retained_before_admission(self):
        script=(ROOT/'scripts/scan-container-artifact.sh').read_text()
        block=script.split('python3 - "$output" "$arch" <<\'PY\'\n',1)[1].split('\nPY',1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            for arch,state in [('amd64','ACTIONABLE'),('arm64','REVIEW_REQUIRED')]:
                d=root/arch;(d/'raw').mkdir(parents=True);(d/'sbom').mkdir()
                (d/'policy-result.json').write_text(json.dumps(dict(gate_state=state,evidence_health=dict(state='HEALTHY'))))
                (d/'subject.json').write_text(json.dumps(dict(oci_digest='sha256:'+'a'*64)))
                (d/'sbom/association.json').write_text(json.dumps(dict(spdx_digest='sha256:'+'b'*64)))
                for scanner in ('grype','trivy'):
                    (d/'raw'/f'{scanner}.json').write_text('{}')
                    (d/f'{scanner}-metadata.json').write_text(json.dumps(dict(
                        scanner_identity=dict(version='0.0.1'),database=dict(identity='fixture-db'),
                        completed_at=NOW,status='SUCCEEDED')))
            for arch in ('amd64','arm64'):
                p=subprocess.run([sys.executable,'-',str(root/arch),arch],input=block,text=True,capture_output=True)
                self.assertEqual(p.returncode,0,p.stderr)
            self.assertEqual([json.loads((root/a/'scan-records.json').read_text())[0]['policy_result']
                              for a in ('amd64','arm64')],['ACTIONABLE','REVIEW_REQUIRED'])



class AdmissionConsumer(unittest.TestCase):
    """Publisher boundary fixtures; never a real operator decision."""

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        (self.root/'.context').mkdir()
        (self.root/'.context/publisher-governance').symlink_to(GOVERNANCE,target_is_directory=True)
        self.prepared=self.root/'prepared';self.prepared.mkdir()
        self.scan=self.root/'scan'
        args=evidence();self.records=args[0];self.smokes=args[4];self.sboms=args[2]
        index=encoded(publisher.assemble_index(
            [r['subject'] for r in self.records],[len(platform(a)[2]) for a in ('amd64','arm64')]))
        self.index=digest(index)
        for name,value in [('index.json',json.loads(index)),('prepared.json',dict(records=self.records,index_digest=self.index)),
                           ('source-security.json',args[5])]:
            (self.prepared/name).write_bytes(encoded(value))
        for arch in ('amd64','arm64'):
            _,config,manifest,_,subject=platform(arch)
            (self.prepared/('config-'+arch+'.json')).write_bytes(config)
            (self.prepared/('platform-'+arch+'.json')).write_bytes(manifest)
            target=dict(platform='linux/'+arch,oci_digest=subject['manifest_digest'])
            folder=self.scan/arch
            (folder/'raw').mkdir(parents=True);(folder/'sbom').mkdir()
            for scanner in ('grype','trivy'):
                (folder/'raw'/f'{scanner}.json').write_bytes(encoded(dict(synthetic=scanner)))
            (folder/'sbom/syft.spdx.json').write_bytes(encoded(dict(synthetic='SPDX fixture')))
            spdx=digest((folder/'sbom/syft.spdx.json').read_bytes())
            next(x for x in self.sboms if x['platform']=='linux/'+arch)['spdx_sha256']=spdx
            association=dict(subject=target,spdx_digest=spdx)
            (folder/'sbom/association.json').write_bytes(encoded(association))
            (folder/'evaluation.json').write_bytes(encoded(dict(subject=target,syft=association,observations=[])))
            (folder/'policy-result.json').write_bytes(encoded(dict(
                gate_state='ACTIONABLE',evidence_health=dict(state='HEALTHY'),
                findings=[dict(synthetic='all original observations retained')])))
            records=[copy.deepcopy(x) for x in args[3] if x['platform']=='linux/'+arch]
            for record in records:
                record['policy_result']='ACTIONABLE'
                record['evidence_sha256']=digest((folder/'raw'/(record['scanner']+'.json')).read_bytes())
                if record['scanner']=='grype':record['subject_digest']=spdx
                (folder/(record['scanner']+'-metadata.json')).write_bytes(encoded(dict(
                    scanner_identity=dict(version=record['scanner_version']),database=record['database'],
                    completed_at=record['completed_at'],status=record['status'])))
            (folder/'scan-records.json').write_bytes(encoded(records))
        (self.prepared/'sboms.json').write_bytes(encoded(self.sboms))
        self.calls=[]
        self.output=self.prepared/'artifact-risk-acceptance.json'

    def invoke(self, denied=False, complete=False, locator='123'):
        from types import SimpleNamespace
        def acceptance(observed,comment_id,**kwargs):
            results=[publisher.read(self.scan/arch/'policy-result.json') for arch in ('amd64','arm64')]
            self.calls.append((observed,results,dict(kwargs,comment_id=comment_id)))
            if denied:raise ValueError('operator acceptance required')
            return dict(admission='ADMITTED_WITH_KNOWN_FINDINGS',source=observed['source'],
                        index_digest=self.index,evidence_sha256=digest(encoded(observed)),
                        expires_at='2026-10-18T08:00:00Z',
                        comment=dict(id=comment_id,body_sha256='sha256:'+'7'*64),
                        operator=dict(id=9,login='synthetic-operator'),policy_results=results)
        adapter=SimpleNamespace(observe_artifact_risk_acceptance=acceptance)
        context=dict(source_commit=SHA,run_id='42',run_attempt='2',scan_attempt='2',execution_attempt='3')
        with patch.object(publisher,'source',return_value=copy.deepcopy(SOURCE)), \
             patch.object(publisher,'run',return_value=('c'*40+'\n').encode()), \
             patch.object(publisher,'security_modules',return_value=[adapter,None]), \
             patch.object(publisher,'observe_policy',side_effect=lambda root,folder,now:publisher.read(folder/'policy-result.json')), \
             patch.object(publisher,'candidate_context',return_value=context), \
             patch.object(publisher,'evidence_now',return_value=NOW), \
             patch.dict(os.environ,GITHUB_SHA=SHA,GITHUB_RUN_ID='42',GITHUB_RUN_ATTEMPT='3',ARTIFACT_RISK_ACCEPTANCE_COMMENT_ID=locator):
            if complete:
                smoke_root=self.root/'smokes'
                for smoke in self.smokes:
                    d=smoke_root/f"api-smoke-{smoke['runner_architecture']}-42-2"
                    d.mkdir(parents=True,exist_ok=True);(d/'smoke.json').write_bytes(encoded(smoke))
                output=self.prepared/'build-evidence.json'
                publisher.complete(self.prepared,self.scan,smoke_root,self.root,output)
                return publisher.read(output)
            return publisher.publication_admission(self.root,self.prepared,self.scan,self.smokes)

    def test_actual_shared_interface_rejects_missing_locator_without_github_reads(self):
        adapter,_=publisher.security_modules(ROOT)
        with self.assertRaisesRegex(ValueError,'ARTIFACT_RISK_AUTHORITY_LOCATION'):
            adapter.observe_artifact_risk_acceptance(
                dict(source=dict(repository='SecPal/api')),0,issue_number=1445,
                get=lambda endpoint:self.fail('invalid locator must not query GitHub'))

    def test_nonclean_without_authority_never_assembles_build_evidence(self):
        (self.prepared/'build-evidence.json').write_text('{}')
        self.output.write_text('{}')
        with self.assertRaises(ValueError):self.invoke(denied=True,complete=True)
        self.assertFalse((self.prepared/'build-evidence.json').exists())
        self.assertFalse(self.output.exists())
        self.assertTrue((self.prepared/'artifact-security-evidence.json').exists())

    def test_no_locator_cannot_select_an_old_approval(self):
        with self.assertRaisesRegex(ValueError,'publisher-operator-risk-acceptance-required'):
            self.invoke(complete=True,locator='')
        self.assertEqual(self.calls,[])
        self.assertFalse((self.prepared/'build-evidence.json').exists())

    def test_exact_acceptance_retains_original_records_and_complete_findings(self):
        document=self.invoke(complete=True)
        receipt=publisher.read(self.output)
        self.assertTrue(all(s['policy_result']=='ACTIONABLE' for s in document['scans']))
        self.assertTrue(all(p['findings'] for p in receipt['policy_results']))
        self.assertEqual(receipt['evidence_sha256'],digest((self.prepared/'artifact-security-evidence.json').read_bytes()))
        self.assertEqual(document['workflow']['run_attempt'],'2')
        self.assertEqual(self.calls[0][2]['issue_number'],1445)
        self.assertEqual(document['smoke_tests'],self.smokes)

    def test_clean_does_not_need_an_operator_or_cached_exceptional_receipt(self):
        for arch in ('amd64','arm64'):
            folder=self.scan/arch
            result=publisher.read(folder/'policy-result.json');result['gate_state']='CLEAN';result['findings']=[]
            (folder/'policy-result.json').write_bytes(encoded(result))
            records=publisher.read(folder/'scan-records.json')
            for r in records:r['policy_result']='CLEAN'
            (folder/'scan-records.json').write_bytes(encoded(records))
        document=self.invoke(denied=True,complete=True)
        self.assertEqual(self.calls,[])
        self.assertNotIn('artifact_security_admission',document['extensions'][publisher.SOURCE])

    def test_stale_refreshed_source_cannot_reach_admission(self):
        source=self.scan/'source';source.mkdir()
        value=source_security();value['database']['next_update']=NOW
        (source/'result.json').write_bytes(encoded(value))
        with self.assertRaises(ValueError):self.invoke(complete=True)
        self.assertEqual(self.calls,[])
        self.assertFalse((self.prepared/'build-evidence.json').exists())

    def test_wrong_oci_spdx_raw_source_and_unhealthy_results_deny_before_authority(self):
        for name in ('config','platform','association','raw','policy'):
            with self.subTest(name=name):
                path=(self.prepared/('config-amd64.json') if name=='config' else
                      self.prepared/'platform-amd64.json' if name=='platform' else
                      self.scan/'amd64/sbom/association.json' if name=='association' else
                      self.scan/'amd64/raw/grype.json' if name=='raw' else
                      self.scan/'amd64/policy-result.json')
                before=path.read_bytes()
                if name=='association':
                    value=json.loads(before);value['subject']['platform']='linux/arm64'
                    path.write_bytes(encoded(value))
                elif name=='policy':
                    value=json.loads(before);value['gate_state']='UNKNOWN_STALE'
                    path.write_bytes(encoded(value))
                else:path.write_bytes(before+b' ')
                self.calls=[]
                with self.assertRaises(ValueError):self.invoke()
                self.assertEqual(self.calls,[])
                path.write_bytes(before)


if __name__=='__main__':
    unittest.main()
