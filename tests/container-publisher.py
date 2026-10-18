#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""API publisher admission fixtures, not native build or registry evidence."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
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
            lambda a:a[4][0].update(workflow_run_id='1'),
            lambda a:a[4].pop(),
        ]
        for mutate in mutations:
            candidate=copy.deepcopy(args);mutate(candidate)
            with self.assertRaises(ValueError):publisher.build_evidence(*candidate,expected_source=SOURCE)
        with self.assertRaises(ValueError):
            publisher.build_evidence(*args,expected_source=dict(SOURCE,commit='0'*40))

    def test_missing_or_malformed_required_evidence_does_not_become_success(self):
        for collection,key,value in [(2,'spdx_sha256','not-a-digest'),(2,'syft_version','moving'),
                                      (3,'evidence_sha256','missing'),(3,'scanner_version','unknown'),
                                      (4,'completed_at','bad-time')]:
            args=evidence();args[collection][0][key]=value
            with self.assertRaises(ValueError):publisher.build_evidence(*args,expected_source=SOURCE)


if __name__=='__main__':
    unittest.main()
