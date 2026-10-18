#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""API publisher source/OCI binding and ADR-021 build-evidence assembly.

Observation lives in source()/bases()/read_layout(); admission and assembly use
their explicit records. Scanner normalization/actionability remains exclusively
in the pinned SecPal/.github implementation, never in this module.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import subprocess

REPOSITORY = 'ghcr.io/secpal/api'
SOURCE = 'https://github.com/SecPal/api'
WORKFLOW = 'SecPal/api/.github/workflows/publish-container.yml'
PLATFORMS = ('linux/amd64', 'linux/arm64')
IMAGE = 'application/vnd.oci.image.manifest.v1+json'
INDEX = 'application/vnd.oci.image.index.v1+json'


def digest(raw):
    return 'sha256:' + hashlib.sha256(raw).hexdigest()


def attestation_filename(subject_digest):
    """Map an exact security identifier to one portable evidence basename."""
    if not isinstance(subject_digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", subject_digest):
        raise ValueError('publisher-attestation-evidence-digest')
    return 'attestation-' + subject_digest.replace(':', '-', 1) + '.json'


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def read(path):
    path = Path(path)
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 32*1024*1024:
        raise ValueError('publisher-evidence-file')
    return json.loads(path.read_bytes())


def write(path, value):
    Path(path).write_bytes(canonical(value) + b'\n')


def run(arguments, *, cwd=None):
    result = subprocess.run(arguments, cwd=cwd, capture_output=True, timeout=180, check=False)
    if result.returncode or len(result.stdout) > 32*1024*1024:
        raise ValueError('publisher-observation-failed:' + arguments[0])
    return result.stdout


def timestamp(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')


def native_architecture(expected, machine, runner):
    actual = {'x86_64':'amd64', 'aarch64':'arm64'}.get(machine)
    if actual != expected or runner not in ({'amd64':'X64','arm64':'ARM64'}.get(expected),):
        raise ValueError('publisher-native-architecture')
    return actual


def source(root, commit):
    root = Path(root).resolve()
    if not re.fullmatch('[0-9a-f]{40}', commit) or run(['git','rev-parse','HEAD'],cwd=root).decode().strip() != commit:
        raise ValueError('publisher-source-commit')
    if run(['git','status','--porcelain','--untracked-files=normal'],cwd=root).strip():
        raise ValueError('publisher-dirty-source')
    epoch = int(run(['git','show','-s','--format=%ct',commit],cwd=root))
    files = {}
    for name in ('Containerfile','.containerignore'):
        raw = run(['git','show',commit+':'+name],cwd=root)
        path = root/name
        if path.is_symlink() or path.read_bytes()!=raw:
            raise ValueError('publisher-source-bytes')
        files[name] = digest(raw)
    return dict(repository=SOURCE, commit=commit, epoch=epoch, context=dict(path='.'),
                containerfile=dict(path='Containerfile',sha256=files['Containerfile']),
                containerignore=dict(path='.containerignore',sha256=files['.containerignore']))


def bases(root, arch):
    if 'linux/'+arch not in PLATFORMS:
        raise ValueError('publisher-base-platform')
    text = (Path(root)/'Containerfile').read_text()
    result = []
    for argument, stage, repository in (('COMPOSER_IMAGE','composer','docker.io/library/composer'),
                                       ('FRANKENPHP_IMAGE','extensions','docker.io/dunglas/frankenphp')):
        match = re.search(r'^ARG '+argument+r'=(\S+)$',text,re.M)
        if not match or not re.fullmatch(re.escape(repository)+r':[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}',match[1]):
            raise ValueError('publisher-reviewed-base')
        reference = match[1]
        raw = run(['skopeo','inspect','--raw','docker://'+repository+'@'+reference.split('@')[1]])
        if digest(raw) != reference.split('@')[1]:
            raise ValueError('publisher-base-index-digest')
        index = json.loads(raw)
        descriptors = [d for d in index['manifests']
                       if d.get('platform',{}).get('os')=='linux'
                       and d['platform'].get('architecture')==arch
                       and d['platform'].get('variant','') in ('','v8')]
        if len(descriptors)!=1 or not re.fullmatch('sha256:[0-9a-f]{64}',descriptors[0]['digest']):
            raise ValueError('publisher-base-descriptor')
        resolved = repository+'@'+descriptors[0]['digest']
        manifest = run(['skopeo','inspect','--raw','docker://'+resolved])
        if digest(manifest)!=descriptors[0]['digest']:
            raise ValueError('publisher-base-manifest-digest')
        result.append(dict(stage=stage,platform='linux/'+arch,reference=reference,digest=digest(manifest)))
    return result


def expected_labels(source_record, base):
    return {
        'org.opencontainers.image.source': SOURCE,
        'org.opencontainers.image.revision':source_record['commit'],
        'org.opencontainers.image.created':timestamp(source_record['epoch']),
        'org.opencontainers.image.licenses':'AGPL-3.0-or-later',
        'org.opencontainers.image.base.name':base['reference'],
        'org.opencontainers.image.base.digest':base['digest'],
        'org.opencontainers.image.title':'SecPal API',
        'org.opencontainers.image.description':'Production API for SecPal operations software',
    }


def admit_platform(manifest_raw, config_raw, descriptor, arch, source_record, base_records):
    if (descriptor.get('mediaType')!=IMAGE or descriptor.get('digest')!=digest(manifest_raw)
            or descriptor.get('size')!=len(manifest_raw)):
        raise ValueError('publisher-platform-manifest')
    manifest = json.loads(manifest_raw)
    if (manifest.get('schemaVersion')!=2 or manifest.get('mediaType')!=IMAGE
            or manifest['config'].get('mediaType')!='application/vnd.oci.image.config.v1+json'
            or manifest['config']['digest']!=digest(config_raw)
            or manifest['config']['size']!=len(config_raw)):
        raise ValueError('publisher-platform-config')
    config = json.loads(config_raw)
    if (config.get('created')!=timestamp(source_record['epoch'])
            or config.get('os')!='linux' or config.get('architecture')!=arch
            or config.get('config',{}).get('User')!='secpal'):
        raise ValueError('publisher-platform-runtime')
    runtime = [b for b in base_records if b['stage']=='extensions' and b['platform']=='linux/'+arch]
    if len(runtime)!=1:
        raise ValueError('publisher-runtime-base')
    labels = expected_labels(source_record,runtime[0])
    if any(config['config'].get('Labels',{}).get(k)!=v for k,v in labels.items()):
        raise ValueError('publisher-platform-labels')
    return dict(platform='linux/'+arch,manifest_digest=digest(manifest_raw),config_digest=digest(config_raw),
                user=config['config']['User'],labels=labels)


def read_layout(layout, arch, source_record, base_records):
    layout = Path(layout)
    if read(layout/'oci-layout')!={'imageLayoutVersion':'1.0.0'}:
        raise ValueError('publisher-oci-layout')
    index = read(layout/'index.json')
    if index.get('schemaVersion')!=2 or len(index.get('manifests',[]))!=1:
        raise ValueError('publisher-local-platform-count')
    descriptor = index['manifests'][0]
    def blob(value):
        if not re.fullmatch('sha256:[0-9a-f]{64}',value):
            raise ValueError('publisher-blob-digest')
        path = layout/'blobs'/'sha256'/value.split(':')[1]
        if path.is_symlink() or not path.is_file():
            raise ValueError('publisher-blob-file')
        return path.read_bytes()
    manifest_raw = blob(descriptor['digest'])
    manifest = json.loads(manifest_raw)
    config_raw = blob(manifest['config']['digest'])
    return admit_platform(manifest_raw,config_raw,descriptor,arch,source_record,base_records),manifest_raw,config_raw


def assemble_index(records, sizes):
    if [r['platform'] for r in records]!=list(PLATFORMS):
        raise ValueError('publisher-complete-platform-set')
    return dict(schemaVersion=2,mediaType=INDEX,manifests=[
        dict(mediaType=IMAGE,digest=r['manifest_digest'],size=size,
             platform=dict(os='linux',architecture=r['platform'].split('/')[1]))
        for r,size in zip(records,sizes,strict=True)])


def admit_source_security(value, commit, observed_at):
    # #1450 owns exact invocation and CLEAN admission; no scanner reinterpretation.
    if (value.get('schema_version')!='secpal-trivy-repository-scan-v2'
            or value.get('subject')!={'repository':'SecPal/api','commit':commit}
            or value.get('gate_state')!='CLEAN'
            or value.get('operation')!={'name':'TRIVY_REPOSITORY_SCAN','status':'SUCCEEDED'}
            or value.get('database',{}).get('status')!='FRESH'):
        raise ValueError('publisher-source-security')
    # Consume the admitted producer deadline; do not define a second max-age policy.
    observed = evidence_time(observed_at)
    database = value['database']
    if not evidence_time(database['updated_at']) <= observed < evidence_time(database['next_update']):
        raise ValueError('publisher-source-security-deadline')
    return value


def evidence_time(value):
    if type(value) is not str or not value.endswith('Z'):
        raise ValueError('publisher-evidence-time')
    try:
        return datetime.fromisoformat(value.replace('Z','+00:00'))
    except ValueError:
        raise ValueError('publisher-evidence-time') from None


def build_evidence(records, index_digest, sboms, scans, smokes, source_security, run_id, attempt, generated_at, *, expected_source):
    if (len(records)!=2 or [r['subject']['platform'] for r in records]!=list(PLATFORMS)
            or records[0]['source']!=records[1]['source']
            or records[0]['builder']!=records[1]['builder']
            or records[0]['source']!=expected_source):
        raise ValueError('publisher-build-record-set')
    source_record = records[0]['source']
    completed = evidence_time(generated_at)
    if (source_record['repository']!=SOURCE
            or records[0]['builder']['name']!='buildah'
            or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',records[0]['builder']['version'])):
        raise ValueError('publisher-build-identity')
    admit_source_security(source_security,source_record['commit'],generated_at)
    if not re.fullmatch('sha256:[0-9a-f]{64}',index_digest):
        raise ValueError('publisher-index-digest')
    for collection, count in ((sboms,2),(scans,4),(smokes,2)):
        if len(collection)!=count:
            raise ValueError('publisher-incomplete-evidence')
    for record in records:
        subject = record['subject']; target=subject['manifest_digest']; p=subject['platform']; arch=p.split('/')[1]
        if record['runner_architecture']!=arch or record['workflow_run_id']!=run_id or record['workflow_run_attempt']!=attempt:
            raise ValueError('publisher-native-build-binding')
        sbom = [s for s in sboms if s['platform']==p]
        smoke = [s for s in smokes if s['platform']==p]
        if (len(sbom)!=1 or sbom[0]['subject_digest']!=target
                or not re.fullmatch(re.escape(REPOSITORY)+r'@sha256:[0-9a-f]{64}',sbom[0]['artifact'])
                or not re.fullmatch(r'sha256:[0-9a-f]{64}',sbom[0]['spdx_sha256'])
                or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',sbom[0]['syft_version'])
                or len(smoke)!=1 or smoke[0]['digest']!=target or smoke[0]['runner_architecture']!=arch
                or smoke[0]['test']!='tests/docker/smoke.sh'
                or smoke[0]['result']!='SUCCEEDED' or smoke[0]['workflow_run_id']!=run_id
                or smoke[0]['workflow_run_attempt']!=attempt):
            raise ValueError('publisher-sbom-or-smoke-binding')
        if evidence_time(smoke[0]['completed_at']) > completed:
            raise ValueError('publisher-smoke-time')
        own = [s for s in scans if s['platform']==p]
        if sorted(s['scanner'] for s in own)!=['grype','trivy']:
            raise ValueError('publisher-scan-set')
        for scan in own:
            expected = sbom[0]['spdx_sha256'] if scan['scanner']=='grype' else target
            if (scan['subject_digest']!=expected or scan['status']!='SUCCEEDED' or scan['policy_result']!='CLEAN'
                    or not re.fullmatch(r'sha256:[0-9a-f]{64}',scan['evidence_sha256'])
                    or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',scan['scanner_version'])
                    or not scan['database']['identity']
                    or evidence_time(scan['completed_at']) > completed):
                raise ValueError('publisher-scan-acceptance')
    return dict(schema_version='1.0.0',generated_at=generated_at,
                subject=dict(repository=REPOSITORY,index_digest=index_digest,platforms=[r['subject'] for r in records]),
                source=source_record,builder=records[0]['builder'],
                workflow=dict(repository='SecPal/api',path='.github/workflows/publish-container.yml',
                              commit=source_record['commit'],run_id=run_id,run_attempt=attempt),
                bases=[b for r in records for b in r['bases']],materials=[],sboms=sboms,scans=scans,smoke_tests=smokes,
                extensions={SOURCE:dict(source_security_sha256=digest(canonical(source_security)),
                                       reproducibility='Source epoch normalized; distribution package resolutions are recorded by Syft, not claimed bit-reproducible.')})


def prepare(root, native, output, security_path):
    """Independently re-observe both native outputs before registry authority."""
    root, native, output = Path(root), Path(native), Path(output)
    output.mkdir(parents=True,exist_ok=True)
    expected = source(root,os.environ['GITHUB_SHA'])
    security = admit_source_security(read(security_path),expected['commit'],timestamp(datetime.now(timezone.utc).timestamp()))
    (output/'source-security.json').write_bytes(canonical(security))
    write(output/'source.json',expected)
    records=[];sizes=[]
    for arch in ('amd64','arm64'):
        directory = native / ('api-native-'+arch+'-'+os.environ['GITHUB_RUN_ID']+'-'+os.environ['GITHUB_RUN_ATTEMPT'])
        record = read(directory/'record.json')
        resolved = bases(root,arch)
        observed,manifest,config = read_layout(directory/'image',arch,expected,resolved)
        if (record['source']!=expected or record['subject']!=observed or record['bases']!=resolved
                or record['runner_architecture']!=arch
                or record['workflow_run_id']!=os.environ['GITHUB_RUN_ID']
                or record['workflow_run_attempt']!=os.environ['GITHUB_RUN_ATTEMPT']):
            raise ValueError('publisher-native-output-binding')
        records.append(record);sizes.append(len(manifest))
        write(output/('record-'+arch+'.json'),record)
        (output/('platform-'+arch+'.json')).write_bytes(manifest)
        (output/('config-'+arch+'.json')).write_bytes(config)
    index = canonical(assemble_index([r['subject'] for r in records],sizes))
    (output/'index.json').write_bytes(index)
    write(output/'prepared.json',dict(index_digest=digest(index),records=records))


def complete(prepared, scan_root, smoke_root, root, output):
    expected = source(root,os.environ['GITHUB_SHA'])
    value = read(Path(prepared)/'prepared.json')
    sboms = read(Path(prepared)/'sboms.json')
    scans=[];smokes=[]
    for arch in ('amd64','arm64'):
        scans.extend(read(Path(scan_root)/arch/'scan-records.json'))
        smoke_dir=Path(smoke_root)/('api-smoke-'+arch+'-'+os.environ['GITHUB_RUN_ID']+'-'+os.environ['GITHUB_RUN_ATTEMPT'])
        smokes.append(read(smoke_dir/'smoke.json'))
    security=read(Path(prepared)/'source-security.json')
    result = build_evidence(value['records'],value['index_digest'],sboms,scans,smokes,security,
                            os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'],
                            datetime.now(timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z'),
                            expected_source=expected)
    # The canonical source-security bytes are retained with this document.
    write(output,result)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    f=sub.add_parser('attestation-filename');f.add_argument('--digest',required=True)
    v=sub.add_parser('source-security');v.add_argument('--input',required=True);v.add_argument('--commit',required=True)
    s=sub.add_parser('source');s.add_argument('--root',required=True);s.add_argument('--commit',required=True);s.add_argument('--output',required=True)
    b=sub.add_parser('bases');b.add_argument('--root',required=True);b.add_argument('--arch',required=True);b.add_argument('--output',required=True)
    r=sub.add_parser('record')
    for key in ('layout','arch','source','bases','output'): r.add_argument('--'+key,required=True)
    a=sub.add_parser('association')
    for key in ('record','spdx','output'):a.add_argument('--'+key,required=True)
    p=sub.add_parser('prepare')
    for key in ('root','native','output','security'):p.add_argument('--'+key,required=True)
    e=sub.add_parser('complete')
    for key in ('prepared','scan-root','smoke-root','root','output'):e.add_argument('--'+key,required=True)
    args=parser.parse_args()
    if args.command=='attestation-filename':print(attestation_filename(args.digest))
    elif args.command=='source-security':admit_source_security(read(args.input),args.commit,timestamp(datetime.now(timezone.utc).timestamp()))
    elif args.command=='source':write(args.output,source(args.root,args.commit))
    elif args.command=='bases':write(args.output,bases(args.root,args.arch))
    elif args.command=='record':
        arch=native_architecture(args.arch,platform.machine(),os.environ.get('RUNNER_ARCH'))
        src,base=read(args.source),read(args.bases)
        subject,_,_=read_layout(args.layout,arch,src,base)
        version=run(['buildah','--version']).decode().split()[2]
        write(args.output,dict(source=src,subject=subject,bases=base,builder=dict(name='buildah',version=version),
                              runner_architecture=arch,workflow_run_id=os.environ['GITHUB_RUN_ID'],
                              workflow_run_attempt=os.environ['GITHUB_RUN_ATTEMPT']))
    elif args.command=='prepare':
        prepare(args.root,args.native,args.output,args.security)
    elif args.command=='complete':
        complete(args.prepared,args.scan_root,args.smoke_root,args.root,args.output)
    else:
        record=read(args.record)
        write(args.output,dict(schema_version='1.0.0',format='SPDX',spdx_digest=digest(Path(args.spdx).read_bytes()),
                               subject=dict(oci_digest=record['subject']['manifest_digest'],platform=record['subject']['platform'])))


if __name__=='__main__':
    try: main()
    except (ValueError,KeyError,TypeError,OSError,json.JSONDecodeError) as error:
        raise SystemExit('FAIL: '+str(error))
