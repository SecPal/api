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
import importlib.util
import json
import os
from pathlib import Path
import platform
import re
import subprocess
import sys

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


def read_bytes(path):
    path = Path(path)
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 32*1024*1024:
        raise ValueError('publisher-evidence-file')
    return path.read_bytes()


def read(path):
    return json.loads(read_bytes(path))


def write(path, value):
    Path(path).write_bytes(canonical(value) + b'\n')


def run(arguments, *, cwd=None):
    result = subprocess.run(arguments, cwd=cwd, capture_output=True, timeout=180, check=False)
    if result.returncode or len(result.stdout) > 32*1024*1024:
        raise ValueError('publisher-observation-failed:' + arguments[0])
    return result.stdout


def timestamp(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')



def evidence_now():
    # Preserve current-time precision; source-epoch formatting stays normalized.
    return datetime.now(timezone.utc).isoformat().replace('+00:00','Z')


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


def source_security_schema(root):
    return read(Path(root)/'.context/publisher-governance/docs/schemas/secpal-trivy-repository-scan-v4.schema.json')


def admit_source_security(value, commit, observed_at, *, schema):
    # #1450 owns exact invocation and CLEAN admission; no scanner reinterpretation.
    from jsonschema import Draft202012Validator, FormatChecker, ValidationError
    try:
        Draft202012Validator(schema,format_checker=FormatChecker()).validate(value)
    except ValidationError:
        raise ValueError('publisher-source-security-schema') from None
    if (value.get('subject')!={'repository':'SecPal/api','commit':commit}
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
    if type(value) is not str or not re.fullmatch(
            r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|\+00:00)', value):
        raise ValueError('publisher-evidence-time')
    try:
        return datetime.fromisoformat(value.replace('Z','+00:00'))
    except ValueError:
        raise ValueError('publisher-evidence-time') from None


def build_evidence(records, index_digest, sboms, scans, smokes, source_security, run_id, attempt, generated_at, *, expected_source, source_security_schema, artifact_admission=None):
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
    admit_source_security(source_security,source_record['commit'],generated_at,schema=source_security_schema)
    if not re.fullmatch('sha256:[0-9a-f]{64}',index_digest):
        raise ValueError('publisher-index-digest')
    for collection, count in ((sboms,2),(scans,4),(smokes,2)):
        if len(collection)!=count:
            raise ValueError('publisher-incomplete-evidence')
    exceptional = artifact_admission is not None
    if exceptional and (
            not isinstance(artifact_admission,dict) or artifact_admission.get('admission')!='ADMITTED_WITH_KNOWN_FINDINGS'
            or artifact_admission.get('source',{}).get('commit')!=source_record['commit']
            or artifact_admission.get('index_digest')!=index_digest
            or evidence_time(artifact_admission['expires_at'])<=completed):
        raise ValueError('publisher-artifact-admission')
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
            if (scan['subject_digest']!=expected or scan['status']!='SUCCEEDED' or scan['policy_result'] not in (('CLEAN','ACTIONABLE','REVIEW_REQUIRED') if exceptional else ('CLEAN',))
                    or not re.fullmatch(r'sha256:[0-9a-f]{64}',scan['evidence_sha256'])
                    or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',scan['scanner_version'])
                    or not scan['database']['identity']
                    or evidence_time(scan['completed_at']) > completed):
                raise ValueError('publisher-scan-acceptance')
    result = dict(schema_version='1.0.0',generated_at=generated_at,
                subject=dict(repository=REPOSITORY,index_digest=index_digest,platforms=[r['subject'] for r in records]),
                source=source_record,builder=records[0]['builder'],
                workflow=dict(repository='SecPal/api',path='.github/workflows/publish-container.yml',
                              commit=source_record['commit'],run_id=run_id,run_attempt=attempt),
                bases=[b for r in records for b in r['bases']],materials=[],sboms=sboms,scans=scans,smoke_tests=smokes,
                extensions={SOURCE:dict(source_security_sha256=digest(canonical(source_security)),
                                       reproducibility='Source epoch normalized; distribution package resolutions are recorded by Syft, not claimed bit-reproducible.')})
    if exceptional:
        result['extensions'][SOURCE]['artifact_security_admission'] = {
            key:value for key,value in artifact_admission.items() if key!='policy_results'
        }
        result['extensions'][SOURCE]['artifact_security_admission']['result_sha256']=digest(canonical(artifact_admission))
    return result



def prepare(root, native, output, security_path):
    """Independently re-observe both native outputs before registry authority."""
    root, native, output = Path(root), Path(native), Path(output)
    output.mkdir(parents=True,exist_ok=True)
    expected = source(root,os.environ['GITHUB_SHA'])
    security = admit_source_security(read(security_path),expected['commit'],evidence_now(),schema=source_security_schema(root))
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



def github(endpoint):
    return json.loads(run(['gh','api','--hostname','github.com','--method','GET',endpoint]))


def candidate_context(commit, run_id, execution_attempt, *, api=github):
    """Observe the one original candidate; failed-job retries do not select new builds."""
    if (not re.fullmatch(r'[a-f0-9]{40}',commit) or not re.fullmatch(r'[1-9][0-9]*',run_id)
            or not re.fullmatch(r'[1-9][0-9]*',execution_attempt)):
        raise ValueError('publisher-candidate-context')
    prefix='repos/SecPal/api'
    listing=api(f'{prefix}/actions/runs/{run_id}/artifacts?per_page=100')
    artifacts=listing['artifacts']
    if listing['total_count']!=len(artifacts):
        raise ValueError('publisher-candidate-artifact-inventory')
    prepared=[a for a in artifacts if re.fullmatch(r'api-prepared-'+run_id+r'-[1-9][0-9]*',a['name'])]
    if len(prepared)!=1:
        raise ValueError('publisher-ambiguous-candidate-attempt')
    attempt=prepared[0]['name'].rsplit('-',1)[1]
    if int(attempt)>int(execution_attempt):
        raise ValueError('publisher-candidate-attempt')
    expected={f'api-prepared-{run_id}-{attempt}',f'api-scans-{run_id}-{attempt}',
              *(f'api-smoke-{a}-{run_id}-{attempt}' for a in ('amd64','arm64'))}
    retained=[a for a in artifacts if a['name'] in expected]
    if (len(retained)!=len(expected) or {a['name'] for a in retained}!=expected
            or any(a['expired'] or not re.fullmatch(r'sha256:[a-f0-9]{64}',a['digest']) for a in retained)):
        raise ValueError('publisher-incomplete-candidate-artifacts')
    origin=api(f'{prefix}/actions/runs/{run_id}/attempts/{attempt}')
    if (origin['id']!=int(run_id) or origin['run_attempt']!=int(attempt)
            or origin['head_sha']!=commit or origin['head_branch']!='main' or origin['event']!='push'
            or origin['path'] not in ('.github/workflows/publish-container.yml','.github/workflows/publish-container.yml@main')
            or origin['repository']['full_name']!='SecPal/api'):
        raise ValueError('publisher-original-run-binding')
    source_commit=api(f'{prefix}/commits/{commit}')
    accepted=api(f'{prefix}/compare/main...{commit}')
    if (source_commit['sha']!=commit or source_commit['commit']['verification']['verified'] is not True
            or accepted['merge_base_commit']['sha']!=commit or accepted['ahead_by']!=0
            or accepted['status'] not in ('identical','behind')):
        raise ValueError('publisher-candidate-not-accepted-source')
    jobs=api(f'{prefix}/actions/runs/{run_id}/attempts/{attempt}/jobs?per_page=100')
    required={'Prepare exact digest subjects','Attest candidate index platforms and Syft artifacts',
              'Repository Security / Accept API Repository Security',
              'Build native API candidates / Native API (amd64)',
              'Build native API candidates / Native API (arm64)',
              'Published native runtime (amd64)','Published native runtime (arm64)',
              'Verify authoritative SPDX and independent artifact scans'}
    selected=[j for j in jobs['jobs'] if j['name'] in required]
    if (jobs['total_count']!=len(jobs['jobs']) or len(selected)!=len(required)
            or {j['name'] for j in selected}!=required
            or any(j['status']!='completed' or j['conclusion']!='success' for j in selected)):
        raise ValueError('publisher-original-execution-incomplete')
    return dict(source_commit=commit,run_id=run_id,run_attempt=attempt,execution_attempt=execution_attempt)


def native_smokes(smoke_root, run_id, attempt):
    return [read(Path(smoke_root)/(f'api-smoke-{arch}-{run_id}-{attempt}')/'smoke.json')
            for arch in ('amd64','arm64')]



def observe_policy(root, folder):
    """Re-observe retained bytes through the accepted adapters and policy, without rescanning."""
    governance=Path(root)/'.context/publisher-governance'
    modules=[]
    for name in ('secpal-vulnerability-reevaluation','secpal-vulnerability-policy'):
        spec=importlib.util.spec_from_file_location(name,governance/'scripts'/(name+'.py'))
        if spec is None or spec.loader is None:
            raise ValueError('publisher-trusted-adapter-unavailable')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);modules.append(module)
    adapter,policy=modules
    spdx=read_bytes(folder/'sbom/syft.spdx.json')
    subject=read(folder/'subject.json')
    association=adapter.verify_syft_association(read(folder/'sbom/association.json'),spdx,subject)
    native=[
        adapter.adapt_grype(read(folder/'raw/grype.json'),read(folder/'grype-metadata.json'),
                           read(folder/'grype-input.json'),spdx,association),
        adapter.adapt_trivy(read(folder/'raw/trivy.json'),read(folder/'trivy-metadata.json')),
    ]
    evaluation=adapter.assemble_evaluation(subject,{
        'format':association['format'],'spdx_digest':association['spdx_digest'],'subject':association['subject'],
    },native,evidence_now())
    recorded=read(folder/'evaluation.json')
    if dict(evaluation,evaluated_at=recorded['evaluated_at'])!=recorded:
        raise ValueError('publisher-normalized-evidence-mismatch')
    vex=[]
    for path in adapter.discover_reviewed_vex_manifests(governance/'reviewed-vex'):
        commit=run(['git','rev-parse','HEAD'],cwd=governance).decode().strip()
        adapter.verify_reviewed_vex_checkout(governance,path,'SecPal/.github',commit)
        vex.append(policy.load_reviewed_vex(path))
    result=policy.evaluate(evaluation,policy.load_policy(governance/'policies/vulnerability-triage-v1.json'),reviewed_vex=vex)
    original=read(folder/'policy-result.json')
    if (result['evidence_health']['state']!='HEALTHY' or result['gate_state']!=original['gate_state']
            or result['findings']!=original['findings']):
        raise ValueError('publisher-current-artifact-policy')
    return result


def observe_candidate(root, prepared, scan_root, smokes):
    """One source/OCI/SBOM/raw observation shared by both admission paths."""
    root,prepared,scan_root=Path(root),Path(prepared),Path(scan_root)
    expected=source(root,os.environ['GITHUB_SHA'])
    security=admit_source_security(read(prepared/'source-security.json'),expected['commit'],
                                  evidence_now(),schema=source_security_schema(root))
    candidate=read(prepared/'prepared.json');records=candidate['records'];sboms=read(prepared/'sboms.json')
    if (len(records)!=2 or [r['subject']['platform'] for r in records]!=list(PLATFORMS)
            or len(sboms)!=2 or [b['platform'] for b in sboms]!=list(PLATFORMS) or smokes is None or len(smokes)!=2):
        raise ValueError('publisher-candidate-record-set')
    index_bytes=read_bytes(prepared/'index.json')
    manifests=[read_bytes(prepared/('platform-'+arch+'.json')) for arch in ('amd64','arm64')]
    if (candidate['index_digest']!=digest(index_bytes)
            or index_bytes!=canonical(assemble_index([r['subject'] for r in records],[len(m) for m in manifests]))):
        raise ValueError('publisher-candidate-index')
    platforms=[]
    for record,sbom,name,manifest,descriptor in zip(records,sboms,PLATFORMS,manifests,json.loads(index_bytes)['manifests'],strict=True):
        arch=name.split('/')[1];folder=scan_root/arch;subject=record['subject']
        observed=admit_platform(manifest,read_bytes(prepared/('config-'+arch+'.json')),descriptor,arch,expected,record['bases'])
        evaluation=read(folder/'evaluation.json');association=read(folder/'sbom/association.json')
        target=dict(platform=name,oci_digest=subject['manifest_digest'])
        if (record['source']!=expected or observed!=subject or evaluation['subject']!=target
                or association['subject']!=target or association['spdx_digest']!=evaluation['syft']['spdx_digest']
                or evaluation['syft']['spdx_digest']!=digest(read_bytes(folder/'sbom/syft.spdx.json'))
                or sbom['subject_digest']!=subject['manifest_digest'] or sbom['spdx_sha256']!=association['spdx_digest']
                or not re.fullmatch(re.escape(REPOSITORY)+r'@sha256:[a-f0-9]{64}',sbom['artifact'])):
            raise ValueError('publisher-candidate-artifact-binding')
        platforms.append(dict(
            config_digest=subject['config_digest'],evaluation=evaluation,
            association_sha256=digest(read_bytes(folder/'sbom/association.json')),
            raw_grype_sha256=digest(read_bytes(folder/'raw/grype.json')),
            raw_trivy_sha256=digest(read_bytes(folder/'raw/trivy.json'))))
    return dict(source=dict(repository='SecPal/api',commit=expected['commit'],
                            tree=run(['git','rev-parse','HEAD^{tree}'],cwd=root).decode().strip()),
                index_digest=candidate['index_digest'],platforms=platforms,
                evidence=dict(source_security=digest(canonical(security)),
                              provenance=digest(canonical(dict(candidate=candidate,sboms=sboms))),
                              native_compatibility=digest(canonical(smokes))))

def publication_admission(root, prepared, scan_root, smokes, *, output_path=None):
    """Only the immutable central implementation may grant exceptional admission."""
    root,prepared,scan_root=Path(root),Path(prepared),Path(scan_root)
    observed=observe_candidate(root,prepared,scan_root,smokes)
    states=[]
    for arch in ('amd64','arm64'):
        policy=observe_policy(root,scan_root/arch)
        records=read(scan_root/arch/'scan-records.json')
        if len(records)!=2 or sorted(r['scanner'] for r in records)!=['grype','trivy']:
            raise ValueError('publisher-scan-set')
        platform_record=next(p for p in observed['platforms'] if p['evaluation']['subject']['platform']=='linux/'+arch)
        target=platform_record['evaluation']['subject']['oci_digest']
        for record in records:
            metadata=read(scan_root/arch/(record['scanner']+'-metadata.json'))
            expected_input=platform_record['evaluation']['syft']['spdx_digest'] if record['scanner']=='grype' else target
            if (record['platform']!='linux/'+arch or record['subject_digest']!=expected_input
                    or record['scanner_version']!=metadata['scanner_identity']['version']
                    or record['database']!=metadata['database'] or record['completed_at']!=metadata['completed_at']):
                raise ValueError('publisher-scan-record-binding')
        if (policy['evidence_health']['state']!='HEALTHY'
                or policy['gate_state'] not in ('CLEAN','ACTIONABLE','REVIEW_REQUIRED')
                or len(records)!=2 or sorted(r['scanner'] for r in records)!=['grype','trivy']
                or any(r['policy_result']!=policy['gate_state'] or r['status']!='SUCCEEDED'
                       or r['evidence_sha256']!=digest(read_bytes(scan_root/arch/'raw'/(r['scanner']+'.json')))
                       for r in records)):
            raise ValueError('publisher-artifact-evidence')
        states.append(policy['gate_state'])
    if states==['CLEAN','CLEAN']:
        return None
    decision=root/'.context/admission-decision'
    commit=run(['git','rev-parse','HEAD'],cwd=decision).decode().strip()
    bundle=decision/'policies/api-1609-security-admission.bundle.json'
    if not bundle.is_file() or not (decision/'policies/api-1609-security-admission.json').is_file():
        raise ValueError('publisher-exact-artifact-admission-required')
    if not bundle.resolve().is_relative_to(decision.resolve()):
        raise ValueError('publisher-admission-bundle-path')
    result=check_security_improvement(
        root,prepared,scan_root,bundle,decision,commit,smokes=smokes,output_path=output_path,observed=observed,
    )
    if output_path is None:
        (prepared/'security-improvement-bundle.json').write_bytes(read_bytes(bundle))
        (prepared/'security-improvement-decision.json').write_bytes(
            read_bytes(decision/'policies/api-1609-security-admission.json'))
    return result

def complete(prepared, scan_root, smoke_root, root, output):
    expected=source(root,os.environ['GITHUB_SHA'])
    context=candidate_context(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
    value=read(Path(prepared)/'prepared.json')
    sboms=read(Path(prepared)/'sboms.json')
    scans=[]
    for arch in ('amd64','arm64'):
        scans.extend(read(Path(scan_root)/arch/'scan-records.json'))
    smokes=native_smokes(smoke_root,context['run_id'],context['run_attempt'])
    admission=publication_admission(root,prepared,scan_root,smokes)
    result=build_evidence(
        value['records'],value['index_digest'],sboms,scans,smokes,
        read(Path(prepared)/'source-security.json'),context['run_id'],context['run_attempt'],
        evidence_now(),
        expected_source=expected,source_security_schema=source_security_schema(root),artifact_admission=admission,
    )
    if context['execution_attempt']!=context['run_attempt']:
        result['extensions'][SOURCE]['acceptance_execution']=dict(
            run_id=context['run_id'],run_attempt=context['execution_attempt'],commit=os.environ['GITHUB_SHA'])
    write(output,result)


def check_security_improvement(root, prepared, scan_root, bundle_path, decision_checkout, decision_commit, *, smokes=None, output_path=None, observed=None):
    """Prepare a separate central decision; never admit a release or rewrite scans.

    The existing trusted-control checkout must pin publisher-governance to the
    reviewed implementation. Neither bundle nor decision selects its code/policy.
    """
    root, prepared, scan_root = Path(root), Path(prepared), Path(scan_root)
    output = Path(output_path) if output_path is not None else prepared/'security-improvement-admission.json'
    output.unlink(missing_ok=True)  # A previous result is never current authority.
    bundle=read(bundle_path)
    observed=observe_candidate(root,prepared,scan_root,smokes) if observed is None else observed
    if (not isinstance(bundle,dict) or any(bundle[key]!=observed[key] for key in ('source','index_digest','platforms'))
            or any(bundle['evidence'][key]!=value for key,value in observed['evidence'].items())):
        raise ValueError('publisher-admission-artifact-evidence')
    runtime=bundle['runtime']
    prefix=f"docker.io/dunglas/frankenphp:{runtime['frankenphp']}-php{runtime['php']}-{runtime['distribution']}@sha256:"
    for record in read(prepared/'prepared.json')['records']:
        base=next(b for b in record['bases'] if b['stage']=='extensions')
        if not base['reference'].startswith(prefix):
            raise ValueError('publisher-admission-runtime')
    governance=root/'.context/publisher-governance'
    run([
        sys.executable,str(governance/'scripts/secpal-vulnerability-reevaluation.py'),
        'admit-api-security-patch','--bundle',str(bundle_path),
        '--decision-checkout',str(decision_checkout),'--trusted-commit',decision_commit,
        '--output',str(output),
    ])
    result=read(output)
    if (result['admission']!='ADMITTED_WITH_KNOWN_FINDINGS'
            or result['bundle_sha256']!=digest(canonical(bundle))
            or result['source']!=bundle['source'] or result['index_digest']!=bundle['index_digest']):
        output.unlink()
        raise ValueError('publisher-admission-result-binding')
    return result



def recheck_admission(root, prepared, scan_root, smoke_root):
    expected=source(root,os.environ['GITHUB_SHA'])
    context=candidate_context(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
    admit_source_security(read(Path(prepared)/'source-security.json'),expected['commit'],
                          evidence_now(),schema=source_security_schema(root))
    temporary=Path(os.environ['RUNNER_TEMP'])/'current-api-admission.json'
    current=publication_admission(
        root,prepared,scan_root,native_smokes(smoke_root,context['run_id'],context['run_attempt']),
        output_path=temporary,
    )
    document=read(Path(prepared)/'build-evidence.json')
    recorded=document['extensions'][SOURCE].get('artifact_security_admission')
    if current is None:
        if recorded is not None or any(s['policy_result']!='CLEAN' for s in document['scans']):
            raise ValueError('publisher-admission-path-changed')
    else:
        retained=read(Path(prepared)/'security-improvement-admission.json')
        if (recorded is None or recorded['result_sha256']!=digest(canonical(retained))
                or any(retained[key]!=current[key] for key in (
                    'admission','expires_at','decision_sha256','bundle_sha256','source','index_digest'))):
            raise ValueError('publisher-admission-decision-changed')
    temporary.unlink(missing_ok=True)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    c=sub.add_parser('candidate-context')
    c.add_argument('--output',required=True)
    c=sub.add_parser('decision-revision')
    c.add_argument('--scan-root',required=True)
    c=sub.add_parser('admission')
    for key in ('root','prepared','scan-root','smoke-root'):
        c.add_argument('--'+key,required=True)
    c=sub.add_parser('check-security-improvement')
    for key in ('root','prepared','scan-root','smoke-root','bundle','decision-checkout','decision-commit'):
        c.add_argument('--'+key,required=True)
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
    if args.command=='candidate-context':
        context=candidate_context(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
        write(args.output,context)
        with open(os.environ['GITHUB_OUTPUT'],'a') as output:
            output.write('candidate_attempt='+context['run_attempt']+'\n')
    elif args.command=='decision-revision':
        policies=[read(Path(args.scan_root)/arch/'policy-result.json') for arch in ('amd64','arm64')]
        if any(p['gate_state']!='CLEAN' for p in policies):
            commit=github('repos/SecPal/.github/commits/main')['sha']
            if not re.fullmatch(r'[a-f0-9]{40}',commit):
                raise ValueError('publisher-central-decision-revision')
            with open(os.environ['GITHUB_OUTPUT'],'a') as output:
                output.write('commit='+commit+'\n')
    elif args.command=='admission':
        recheck_admission(args.root,args.prepared,args.scan_root,args.smoke_root)
    elif args.command=='check-security-improvement':
        records=read(Path(args.prepared)/'prepared.json')['records']
        check_security_improvement(
            args.root,args.prepared,args.scan_root,args.bundle,args.decision_checkout,args.decision_commit,
            smokes=native_smokes(args.smoke_root,records[0]['workflow_run_id'],records[0]['workflow_run_attempt']))
    elif args.command=='attestation-filename':print(attestation_filename(args.digest))
    elif args.command=='source-security':admit_source_security(read(args.input),args.commit,evidence_now(),schema=source_security_schema(Path(__file__).resolve().parents[1]))
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
