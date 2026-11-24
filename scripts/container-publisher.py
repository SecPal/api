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

RUNTIMES = {
    'php-fpm': dict(containerfile='docker/php-fpm/Containerfile',
                    repository='docker.io/library/php', composer=True),
    'nginx': dict(containerfile='docker/nginx/Containerfile',
                  repository='docker.io/library/nginx', composer=False),
}
RUNTIME_COMPONENTS = tuple(RUNTIMES)


def runtime_definition(runtime):
    if runtime not in RUNTIMES:
        raise ValueError('publisher-runtime-component')
    return dict(component=runtime, **RUNTIMES[runtime])



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


def source(root, commit, runtime):
    root = Path(root).resolve()
    if not re.fullmatch('[0-9a-f]{40}', commit) or run(['git','rev-parse','HEAD'],cwd=root).decode().strip() != commit:
        raise ValueError('publisher-source-commit')
    if run(['git','status','--porcelain','--untracked-files=normal'],cwd=root).strip():
        raise ValueError('publisher-dirty-source')
    epoch = int(run(['git','show','-s','--format=%ct',commit],cwd=root))
    definition = runtime_definition(runtime)
    containerfile = definition['containerfile']
    files = {}
    for name in (containerfile,'.containerignore'):
        raw = run(['git','show',commit+':'+name],cwd=root)
        path = root/name
        if path.is_symlink() or path.read_bytes()!=raw:
            raise ValueError('publisher-source-bytes')
        files[name] = digest(raw)
    return dict(repository=SOURCE, commit=commit, epoch=epoch, runtime=runtime, context=dict(path='.'),
                containerfile=dict(path=containerfile,sha256=files[containerfile]),
                containerignore=dict(path='.containerignore',sha256=files['.containerignore']))


def reviewed_bases(text, runtime):
    definition = runtime_definition(runtime)
    # Keep base declarations in the literal, single-line grammar shared with Dependabot.
    if (text.startswith('\ufeff') or '<<' in text
            or re.search(r'^\s*#\s*(?:escape|syntax)\s*=', text, re.I | re.M)):
        raise ValueError('publisher-base-stage-syntax')

    runtime_stage = 'api-base' if definition['composer'] else 'runtime'
    parents = {runtime_stage: definition['repository']}
    if definition['composer']:
        parents = {
            'composer': 'docker.io/library/composer',
            **parents,
            'sqlite-build': 'api-base',
            'extensions': 'api-base',
            'dependencies': 'extensions',
            'runtime': 'extensions',
        }
    stages = {}
    continued = False
    for line in text.split('\n'):
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        if re.match(r'^\s*FROM\b', line, re.I):
            match = re.fullmatch(r'FROM (\S+) AS ([a-z][a-z0-9-]*)', line)
            if match is None or continued:
                raise ValueError('publisher-base-stage-syntax')
            reference, stage = match.groups()
            if stage in stages:
                raise ValueError('publisher-base-stage')
            stages[stage] = reference
        continued = line.rstrip().endswith('\\')

    if list(stages) != list(parents):
        raise ValueError('publisher-base-stage')
    pins = {}
    for stage, parent in parents.items():
        reference = stages[stage]
        if stage in ('composer', runtime_stage):
            if not re.fullmatch(re.escape(parent)+r':[A-Za-z0-9_][A-Za-z0-9._-]{0,127}@sha256:[0-9a-f]{64}',
                                reference):
                raise ValueError('publisher-reviewed-base')
            pins['extensions' if stage == runtime_stage else stage] = reference
        elif reference != parent:
            raise ValueError('publisher-base-stage-parent')
    return pins


def bases(root, arch, runtime):
    if 'linux/'+arch not in PLATFORMS:
        raise ValueError('publisher-base-platform')
    definition = runtime_definition(runtime)
    text = (Path(root)/definition['containerfile']).read_text()
    result = []
    index_types = (INDEX, 'application/vnd.docker.distribution.manifest.list.v2+json')
    manifest_types = (IMAGE, 'application/vnd.docker.distribution.manifest.v2+json')
    for stage, reference in reviewed_bases(text, runtime).items():
        tagged, index_digest = reference.split('@')
        repository = tagged.rsplit(':', 1)[0]
        raw = run(['skopeo','inspect','--raw','docker://'+repository+'@'+index_digest])
        if digest(raw) != index_digest:
            raise ValueError('publisher-base-index-digest')
        index = json.loads(raw)
        if index.get('schemaVersion') != 2 or index.get('mediaType') not in index_types:
            raise ValueError('publisher-base-index')
        variants = ('', 'v8') if arch == 'arm64' else ('',)
        descriptors = [d for d in index['manifests']
                       if d.get('platform',{}).get('os')=='linux'
                       and d['platform'].get('architecture')==arch
                       and d['platform'].get('variant','') in variants]
        if (len(descriptors) != 1 or not re.fullmatch('sha256:[0-9a-f]{64}', descriptors[0]['digest'])
                or descriptors[0].get('mediaType') not in manifest_types):
            raise ValueError('publisher-base-descriptor')
        descriptor = descriptors[0]
        manifest = run(['skopeo','inspect','--raw','docker://'+repository+'@'+descriptor['digest']])
        if digest(manifest) != descriptor['digest'] or len(manifest) != descriptor.get('size'):
            raise ValueError('publisher-base-manifest-digest')
        child = json.loads(manifest)
        if child.get('schemaVersion') != 2 or child.get('mediaType') != descriptor['mediaType']:
            raise ValueError('publisher-base-manifest')
        result.append(dict(stage=stage, platform='linux/'+arch, reference=reference, digest=descriptor['digest']))
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
        'secpal.dev/runtime-component':runtime_definition(source_record['runtime'])['component'],
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
    for layer in manifest['layers']:
        value = layer['digest']
        if not re.fullmatch(r'sha256:[0-9a-f]{64}',value):
            raise ValueError('publisher-layer-digest')
        path = layout/'blobs'/'sha256'/value.split(':')[1]
        if path.is_symlink() or not path.is_file() or path.stat().st_size != layer['size']:
            raise ValueError('publisher-layer-file')
        with path.open('rb') as stream:
            if 'sha256:'+hashlib.file_digest(stream,'sha256').hexdigest() != value:
                raise ValueError('publisher-layer-bytes')
    return admit_platform(manifest_raw,config_raw,descriptor,arch,source_record,base_records),manifest_raw,config_raw


def assemble_index(records, sizes):
    if [r['platform'] for r in records]!=list(PLATFORMS):
        raise ValueError('publisher-complete-platform-set')
    return dict(schemaVersion=2,mediaType=INDEX,manifests=[
        dict(mediaType=IMAGE,digest=r['manifest_digest'],size=size,
             platform=dict(os='linux',architecture=r['platform'].split('/')[1]))
        for r,size in zip(records,sizes,strict=True)])


def source_security_schema(root):
    return read(Path(root)/'.context/publisher-governance/docs/schemas/secpal-trivy-repository-scan-v5.schema.json')


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


def build_evidence(records, index_digest, sboms, scans, smokes, source_security, run_id, attempt, generated_at, *, expected_source, source_security_schema):
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
    result = dict(schema_version='1.0.0',generated_at=generated_at,
                subject=dict(repository=REPOSITORY,index_digest=index_digest,platforms=[r['subject'] for r in records]),
                source=source_record,builder=records[0]['builder'],
                workflow=dict(repository='SecPal/api',path='.github/workflows/publish-container.yml',
                              commit=source_record['commit'],run_id=run_id,run_attempt=attempt),
                bases=[b for r in records for b in r['bases']],materials=[],sboms=sboms,scans=scans,smoke_tests=smokes,
                extensions={SOURCE:dict(source_security_sha256=digest(canonical(source_security)),
                                       reproducibility='Source epoch normalized; distribution package resolutions are recorded by Syft, not claimed bit-reproducible.')})
    return result




def native_attempt(arch):
    """Retain successful PR builds when only their security job is retried."""
    current = os.environ['GITHUB_RUN_ATTEMPT']
    retained = os.environ.get('API_NATIVE_'+arch.upper()+'_ATTEMPT')
    if retained is None:
        return current
    if (not re.fullmatch(r'[1-9][0-9]*', retained)
            or int(retained) > int(current)
            or (retained != current and os.environ.get('GITHUB_EVENT_NAME') != 'pull_request')):
        raise ValueError('publisher-native-attempt')
    return retained


def observe_native(root, directory, arch, expected, *, layout=None, attempt=None):
    directory = Path(directory)
    record = read(directory/'record.json')
    resolved = bases(root,arch,expected['runtime'])
    observed,manifest,config = read_layout(directory/'image' if layout is None else layout,arch,expected,resolved)
    if (record['source']!=expected or record['subject']!=observed or record['bases']!=resolved
            or record['runner_architecture']!=arch
            or record['workflow_run_id']!=os.environ['GITHUB_RUN_ID']
            or record['workflow_run_attempt']!=(attempt or os.environ['GITHUB_RUN_ATTEMPT'])):
        raise ValueError('publisher-native-output-binding')
    return record,manifest,config


def prepare(root, native, output, security_path, runtime):
    """Independently re-observe both native outputs before registry authority."""
    root, native, output = Path(root), Path(native), Path(output)
    output.mkdir(parents=True,exist_ok=True)
    expected = source(root,os.environ['GITHUB_SHA'],runtime)
    security = admit_source_security(read(security_path),expected['commit'],evidence_now(),schema=source_security_schema(root))
    (output/'source-security.json').write_bytes(canonical(security))
    write(output/'source.json',expected)
    records=[];sizes=[]
    for arch in ('amd64','arm64'):
        directory = native / ('api-native-'+arch+'-'+os.environ['GITHUB_RUN_ID']+'-'+os.environ['GITHUB_RUN_ATTEMPT'])
        record,manifest,config = observe_native(root,directory/runtime,arch,expected)
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
    scans=[a for a in artifacts if re.fullmatch(r'api-scans-'+run_id+r'-[1-9][0-9]*',a['name'])]
    if not scans:
        raise ValueError('publisher-incomplete-candidate-artifacts')
    scan_attempt=str(max(int(a['name'].rsplit('-',1)[1]) for a in scans))
    if not int(attempt)<=int(scan_attempt)<=int(execution_attempt):
        raise ValueError('publisher-scan-attempt')
    expected={f'api-prepared-{run_id}-{attempt}',f'api-scans-{run_id}-{scan_attempt}',
              *(f'api-smoke-{a}-{run_id}-{attempt}' for a in ('amd64','arm64'))}
    retained=[a for a in artifacts if a['name'] in expected]
    if (len(retained)!=len(expected) or {a['name'] for a in retained}!=expected
            or any(a['expired'] or not re.fullmatch(r'sha256:[a-f0-9]{64}',a['digest']) for a in retained)):
        raise ValueError('publisher-incomplete-candidate-artifacts')
    origin=api(f'{prefix}/actions/runs/{run_id}/attempts/{attempt}')
    if (origin['id']!=int(run_id) or origin['run_attempt']!=int(attempt)
            or origin['head_sha']!=commit or origin['head_branch']!='main'
            or origin['event']!='workflow_dispatch'
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
    required={'Prepare exact digest subjects',
              'Repository Security / Accept API Repository Security',
              'Build native API candidates / Native API (amd64)',
              'Build native API candidates / Native API (arm64)',
              'Published native runtime (amd64)','Published native runtime (arm64)'}
    required.update('Attest candidate index platforms and Syft artifacts ('+role+')'
                    for role in RUNTIME_COMPONENTS)
    selected=[j for j in jobs['jobs'] if j['name'] in required]
    if (jobs['total_count']!=len(jobs['jobs']) or len(selected)!=len(required)
            or {j['name'] for j in selected}!=required
            or any(j['status']!='completed' or j['conclusion']!='success' for j in selected)):
        raise ValueError('publisher-original-execution-incomplete')
    scan_jobs=jobs if scan_attempt==attempt else api(f'{prefix}/actions/runs/{run_id}/attempts/{scan_attempt}/jobs?per_page=100')
    scan_job=[j for j in scan_jobs['jobs'] if j['name']=='Verify authoritative SPDX and independent artifact scans']
    if (scan_jobs['total_count']!=len(scan_jobs['jobs']) or len(scan_job)!=1
            or scan_job[0]['status']!='completed' or scan_job[0]['conclusion']!='success'):
        raise ValueError('publisher-scan-execution-incomplete')
    return dict(source_commit=commit,run_id=run_id,run_attempt=attempt,
                scan_attempt=scan_attempt,execution_attempt=execution_attempt)


def native_smokes(smoke_root, run_id, attempt, runtime):
    return [read(Path(smoke_root)/(f'api-smoke-{arch}-{run_id}-{attempt}') /
                 runtime/'smoke.json')
            for arch in ('amd64','arm64')]



def security_modules(root):
    """Load the two existing owners from the accepted immutable checkout."""
    governance=Path(root)/'.context/publisher-governance'
    modules=[]
    for name in ('secpal-vulnerability-reevaluation','secpal-vulnerability-policy'):
        spec=importlib.util.spec_from_file_location(name,governance/'scripts'/(name+'.py'))
        if spec is None or spec.loader is None:
            raise ValueError('publisher-trusted-adapter-unavailable')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);modules.append(module)
    return modules


def observe_policy(root, folder, observed_at):
    """Re-observe retained bytes with the existing adapters and current policy."""
    adapter,policy=security_modules(root)
    governance=Path(root)/'.context/publisher-governance'
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
    },native,observed_at)
    recorded=read(folder/'evaluation.json')
    if dict(evaluation,evaluated_at=recorded['evaluated_at'])!=recorded:
        raise ValueError('publisher-normalized-evidence-mismatch')
    vex=[]
    vex_checkout = Path(root)/'.context/reviewed-vex'
    for path in adapter.discover_reviewed_vex_manifests(vex_checkout/'reviewed-vex'):
        commit=run(['git','rev-parse','HEAD'],cwd=vex_checkout).decode().strip()
        adapter.verify_reviewed_vex_checkout(vex_checkout,path,'SecPal/.github',commit)
        vex.append(policy.load_reviewed_vex(path))
    result=policy.evaluate(evaluation,policy.load_policy(governance/'policies/vulnerability-triage-v1.json'),reviewed_vex=vex)
    original=read(folder/'policy-result.json')
    if (result['evidence_health']['state']!='HEALTHY' or result['gate_state']!=original['gate_state']
            or result['findings']!=original['findings']):
        raise ValueError('publisher-current-artifact-policy')
    return result


def observe_candidate(root, prepared, scan_root):
    """Bind the retained evaluation and SPDX to the exact prepared OCI subjects."""
    root,prepared,scan_root=Path(root),Path(prepared),Path(scan_root)
    runtime = read(Path(prepared)/'source.json')['runtime']
    expected=source(root,os.environ['GITHUB_SHA'],runtime)
    admit_source_security(read(prepared/'source-security.json'),expected['commit'],
                                  evidence_now(),schema=source_security_schema(root))
    candidate=read(prepared/'prepared.json');records=candidate['records'];sboms=read(prepared/'sboms.json')
    if (len(records)!=2 or [r['subject']['platform'] for r in records]!=list(PLATFORMS)
            or len(sboms)!=2 or [b['platform'] for b in sboms]!=list(PLATFORMS)):
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
        platforms.append(evaluation)
    return platforms


def publication_admission(root, prepared, scan_root):
    # Recheck original observations under current policy for both platforms.
    scan_root=Path(scan_root)
    platforms=observe_candidate(root,prepared,scan_root)
    observed_at=evidence_now()
    for arch,evaluation in zip(('amd64','arm64'),platforms,strict=True):
        policy=observe_policy(root,scan_root/arch,observed_at)
        records=read(scan_root/arch/'scan-records.json')
        if len(records)!=2 or sorted(r['scanner'] for r in records)!=['grype','trivy']:
            raise ValueError('publisher-scan-set')
        target=evaluation['subject']['oci_digest']
        for record in records:
            metadata=read(scan_root/arch/(record['scanner']+'-metadata.json'))
            expected_input=evaluation['syft']['spdx_digest'] if record['scanner']=='grype' else target
            if (record['platform']!='linux/'+arch or record['subject_digest']!=expected_input
                    or record['scanner_version']!=metadata['scanner_identity']['version']
                    or record['database']!=metadata['database'] or record['completed_at']!=metadata['completed_at']):
                raise ValueError('publisher-scan-record-binding')
        if (policy['evidence_health']['state']!='HEALTHY'
                or policy['gate_state'] not in ('CLEAN','ACTIONABLE','REVIEW_REQUIRED')
                or any(r['policy_result']!=policy['gate_state'] or r['status']!='SUCCEEDED'
                       or r['evidence_sha256']!=digest(read_bytes(scan_root/arch/'raw'/(r['scanner']+'.json')))
                       for r in records)):
            raise ValueError('publisher-artifact-evidence')
        if policy['gate_state']!='CLEAN':
            raise ValueError('publisher-runtime-security-not-clean')


def complete(prepared, scan_root, smoke_root, root, output):
    Path(output).unlink(missing_ok=True)
    runtime = read(Path(prepared)/'source.json')['runtime']
    expected=source(root,os.environ['GITHUB_SHA'],runtime)
    context=candidate_context(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
    value=read(Path(prepared)/'prepared.json')
    sboms=read(Path(prepared)/'sboms.json')
    scans=[]
    for arch in ('amd64','arm64'):
        scans.extend(read(Path(scan_root)/arch/'scan-records.json'))
    smokes=native_smokes(smoke_root,context['run_id'],context['run_attempt'],runtime)
    fresh_source=Path(scan_root).parent/'source/result.json'
    if fresh_source.is_file():
        admit_source_security(read(fresh_source),expected['commit'],evidence_now(),schema=source_security_schema(root))
        (Path(prepared)/'source-security.json').write_bytes(read_bytes(fresh_source))
    publication_admission(root,prepared,scan_root)
    result=build_evidence(
        value['records'],value['index_digest'],sboms,scans,smokes,
        read(Path(prepared)/'source-security.json'),context['run_id'],context['run_attempt'],
        evidence_now(),
        expected_source=expected,source_security_schema=source_security_schema(root),
    )
    if context['execution_attempt']!=context['run_attempt']:
        result['extensions'][SOURCE]['acceptance_execution']=dict(
            run_id=context['run_id'],run_attempt=context['execution_attempt'],commit=os.environ['GITHUB_SHA'])
    write(output,result)


def qualifies_unpublished_policy(policy, *, require_clean=False):
    """Keep PR diagnostics separate from the publication decision."""
    allowed = ('CLEAN',) if require_clean else ('CLEAN', 'ACTIONABLE', 'REVIEW_REQUIRED')
    return policy['evidence_health']['state'] == 'HEALTHY' and policy['gate_state'] in allowed


def verify_changed_base_tags(root, base, paths):
    """Check new bot pins against the upstream tags without repinning existing builds."""
    if not re.fullmatch(r'[a-f0-9]{40}', base):
        raise ValueError('publisher-base-tag-source')
    replacements = set()
    for role, definition in RUNTIMES.items():
        path = definition['containerfile']
        if path in paths:
            before = reviewed_bases(run(['git', 'show', base+':'+path], cwd=root).decode(), role)
            after = reviewed_bases((Path(root)/path).read_text(), role)
            replacements.update(pin for stage, pin in after.items() if pin != before[stage])
    for pin in sorted(replacements):
        tagged, index = pin.split('@')
        if digest(run(['skopeo', 'inspect', '--raw', 'docker://'+tagged])) != index:
            raise ValueError('publisher-base-tag-digest')


def observe_unpublished_runtime_scans(root, scan_root):
    observed_at = evidence_now()
    for role in RUNTIME_COMPONENTS:
        for arch in ('amd64', 'arm64'):
            observe_policy(root, scan_root / role / arch, observed_at)


def qualify_unpublished_pair(root, native, scan_root, output, *, require_clean=False):
    """Re-observe all native artifacts before returning a pair qualification."""
    root, native, scan_root, output = map(Path, (root, native, scan_root, output))
    output.unlink(missing_ok=True)
    commit = os.environ['GITHUB_SHA']
    run_id, attempt = os.environ['GITHUB_RUN_ID'], os.environ['GITHUB_RUN_ATTEMPT']
    observed_at = evidence_now()
    trusted_candidate = os.environ.get('GITHUB_EVENT_NAME') in ('push', 'workflow_dispatch')
    images, companions, manifest_digests = {}, {}, set()
    for role in RUNTIME_COMPONENTS:
        expected = source(root, commit, role)
        platforms, sizes = [], []
        for arch in ('amd64', 'arm64'):
            build_attempt = native_attempt(arch)
            own = native/f'api-native-{arch}-{run_id}-{build_attempt}'/role
            folder = scan_root/role/arch
            record, manifest, _ = observe_native(root, own, arch, expected, attempt=build_attempt)
            subject = record['subject']
            target = dict(platform='linux/'+arch, oci_digest=subject['manifest_digest'])
            policy = observe_policy(root, folder, observed_at)
            if not qualifies_unpublished_policy(policy, require_clean=require_clean):
                raise ValueError(
                    f"publisher-unpublished-pair-policy: {role} linux/{arch} "
                    f"{target['oci_digest']} {policy['gate_state']}")
            association = read(folder/'sbom/association.json')
            spdx_digest = digest(read_bytes(folder/'sbom/syft.spdx.json'))
            smoke = read(own/'smoke.json')
            scans = read(folder/'scan-records.json')
            if (record['source'] != expected or subject['platform'] != target['platform']
                    or subject['labels'].get('secpal.dev/runtime-component') != role
                    or subject['manifest_digest'] in manifest_digests
                    or read(folder/'evaluation.json')['subject'] != target
                    or association['subject'] != target or association['spdx_digest'] != spdx_digest
                    or ((require_clean or trusted_candidate) and (association != read(own/'association.json')
                                           or spdx_digest != digest(read_bytes(own/'syft.spdx.json'))))
                    or smoke['platform'] != target['platform'] or smoke['digest'] != target['oci_digest']
                    or smoke['runner_architecture'] != arch or smoke['test'] != 'tests/docker/smoke.sh'
                    or smoke['workflow_run_id'] != run_id or smoke['workflow_run_attempt'] != build_attempt
                    or smoke['result'] != 'SUCCEEDED'
                    or evidence_time(smoke['completed_at']) > evidence_time(observed_at)
                    or len(scans) != 2 or sorted(v['scanner'] for v in scans) != ['grype', 'trivy']):
                raise ValueError('publisher-unpublished-pair-binding')
            for scan in scans:
                scanner = scan['scanner']
                metadata = read(folder/(scanner+'-metadata.json'))
                expected_input = spdx_digest if scanner == 'grype' else target['oci_digest']
                if (scan['platform'] != target['platform'] or scan['subject_digest'] != expected_input
                        or scan['status'] != 'SUCCEEDED' or metadata['status'] != 'SUCCEEDED'
                        or scan['policy_result'] != policy['gate_state']
                        or scan['scanner_version'] != metadata['scanner_identity']['version']
                        or scan['database'] != metadata['database']
                        or scan['completed_at'] != metadata['completed_at']
                        or evidence_time(scan['completed_at']) > evidence_time(observed_at)
                        or scan['evidence_sha256'] != digest(read_bytes(folder/'raw'/(scanner+'.json')))):
                    raise ValueError('publisher-unpublished-pair-scanner')
            manifest_digests.add(subject['manifest_digest'])
            companions[(arch, role)] = smoke['runtime_pair']
            sizes.append(len(manifest))
            platforms.append(dict(subject=subject, sbom_sha256=spdx_digest, scans=scans,
                                  smoke=smoke, policy_sha256=digest(canonical(policy))))
        subjects = [p['subject'] for p in platforms]
        images[role] = dict(candidate_index_digest=digest(canonical(assemble_index(subjects, sizes))),
                            platforms=platforms)
    for (arch, _), pair in companions.items():
        expected = {role: next(p['subject']['manifest_digest'] for p in images[role]['platforms']
                               if p['subject']['platform'] == 'linux/'+arch)
                    for role in RUNTIME_COMPONENTS}
        if pair != expected:
            raise ValueError('publisher-unpublished-pair-companions')
    source_record = dict(repository=SOURCE, commit=commit,
                         tree=run(['git', 'rev-parse', 'HEAD^{tree}'], cwd=root).decode().strip())
    binding = dict(source=source_record, images=images)
    write(output, dict(schema_version='1.0.0', published=False, qualification='SUCCEEDED',
                       generated_at=observed_at, pair_sha256=digest(canonical(binding)), **binding,
                       workflow=dict(repository='SecPal/api', path='.github/workflows/container-image.yml',
                                     run_id=run_id, run_attempt=attempt)))


def assemble_runtime_pair(documents, commit, generated_at):
    """Bind the two existing complete per-image admissions into one artifact."""
    if (set(documents) != set(RUNTIME_COMPONENTS)
            or not re.fullmatch(r'[a-f0-9]{40}', commit)):
        raise ValueError('publisher-runtime-pair-components')
    if any([p['platform'] for p in documents[role]['subject']['platforms']] != list(PLATFORMS)
           for role in RUNTIME_COMPONENTS):
        raise ValueError('publisher-runtime-pair-platforms')
    workflow = None
    images = {}
    indexes = set()
    for component in RUNTIME_COMPONENTS:
        document = documents[component]
        source_record = document['source']
        current_workflow = document['workflow']
        subjects = document['subject']['platforms']
        if (source_record.get('runtime') != component or source_record['repository'] != SOURCE
                or source_record['commit'] != commit
                or document['subject']['repository'] != REPOSITORY
                or current_workflow.get('repository') != 'SecPal/api'
                or current_workflow.get('path') != '.github/workflows/publish-container.yml'
                or current_workflow.get('commit') != commit
                or (workflow is not None and workflow != current_workflow)
                or [p['platform'] for p in subjects] != list(PLATFORMS)
                or any(p['labels'].get('secpal.dev/runtime-component') != component for p in subjects)
                or evidence_time(document['generated_at']) > evidence_time(generated_at)
                or document['extensions'][SOURCE].get('artifact_security_admission') is not None):
            raise ValueError('publisher-runtime-pair-binding')
        workflow = current_workflow
        index_digest = document['subject']['index_digest']
        if not re.fullmatch(r'sha256:[a-f0-9]{64}', index_digest) or index_digest in indexes:
            raise ValueError('publisher-runtime-pair-index')
        indexes.add(index_digest)
        if len(document['scans']) != 4 or len(document['sboms']) != 2 or len(document['smoke_tests']) != 2:
            raise ValueError('publisher-runtime-pair-incomplete')
        for subject in subjects:
            own = [v for v in document['scans'] if v['platform'] == subject['platform']]
            sboms = [v for v in document['sboms'] if v['platform'] == subject['platform']]
            smokes = [v for v in document['smoke_tests'] if v['platform'] == subject['platform']]
            if (sorted(v['scanner'] for v in own) != ['grype','trivy']
                    or any(v['status'] != 'SUCCEEDED' or v['policy_result'] != 'CLEAN' for v in own)
                    or len(sboms) != 1 or sboms[0]['subject_digest'] != subject['manifest_digest']
                    or len(smokes) != 1 or smokes[0]['digest'] != subject['manifest_digest']
                    or smokes[0]['result'] != 'SUCCEEDED'):
                raise ValueError('publisher-runtime-pair-qualification')
        for smoke in document['smoke_tests']:
            arch = smoke['platform']
            expected_pair = {
                role:next(p['manifest_digest'] for p in documents[role]['subject']['platforms']
                          if p['platform'] == arch)
                for role in RUNTIME_COMPONENTS
            }
            if smoke.get('runtime_pair') != expected_pair:
                raise ValueError('publisher-runtime-pair-smoke-companions')
        images[component] = dict(reference=REPOSITORY+'@'+index_digest, platforms=subjects,
                                 build_evidence_sha256=digest(canonical(document)+b'\n'))
    return dict(schema_version='1.0.0', generated_at=generated_at,
                source=dict(repository=SOURCE,commit=commit), workflow=workflow, images=images)


def complete_runtime_pair(root, prepared, scan_root, smoke_root, output):
    """No pair exists until both existing full evidence paths succeed."""
    Path(output).unlink(missing_ok=True)
    documents = {}
    for component in RUNTIME_COMPONENTS:
        own = Path(prepared)/component
        complete(own,Path(scan_root)/component,smoke_root,root,own/'build-evidence.json')
        documents[component] = read(own/'build-evidence.json')
    write(output,assemble_runtime_pair(documents,os.environ['GITHUB_SHA'],evidence_now()))


def recheck_runtime_pair(root, prepared, scan_root, smoke_root):
    context=candidate_context(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
    documents = {}
    for component in RUNTIME_COMPONENTS:
        own = Path(prepared)/component
        publication_admission(root,own,Path(scan_root)/component)
        document = read(own/'build-evidence.json')
        smokes = native_smokes(smoke_root,context['run_id'],context['run_attempt'],component)
        if (document['smoke_tests']!=smokes or 'artifact_security_admission' in document['extensions'][SOURCE]
                or any(s['policy_result']!='CLEAN' for s in document['scans'])):
            raise ValueError('publisher-admission-evidence-changed')
        documents[component] = document
    retained = read(Path(prepared)/'runtime-pair.json')
    expected = assemble_runtime_pair(documents,os.environ['GITHUB_SHA'],retained['generated_at'])
    if retained != expected:
        raise ValueError('publisher-runtime-pair-substitution')


def component_subjects(prepared):
    value = read(Path(prepared)/'prepared.json')
    sboms = read(Path(prepared)/'sboms.json')
    result = dict(index_digest=value['index_digest'])
    for arch in ('amd64','arm64'):
        platform_name = 'linux/'+arch
        records = [r for r in value['records'] if r['subject']['platform'] == platform_name]
        own = [r for r in sboms if r['platform'] == platform_name]
        if len(records) != 1 or len(own) != 1:
            raise ValueError('publisher-component-subjects')
        result[arch+'_digest'] = records[0]['subject']['manifest_digest']
        result[arch+'_sbom_digest'] = own[0]['artifact'].split('@')[1]
    if any(not re.fullmatch(r'sha256:[a-f0-9]{64}', value) for value in result.values()):
        raise ValueError('publisher-component-subject-digest')
    return result


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    c=sub.add_parser('candidate-context')
    c.add_argument('--output',required=True)
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
    for name in ('source','bases','prepare'):
        sub.choices[name].add_argument('--runtime',choices=tuple(RUNTIMES),required=True)
    c=sub.add_parser('verify-native')
    for key in ('root','directory','arch','output'):
        c.add_argument('--'+key,required=True)
    c.add_argument('--runtime',choices=tuple(RUNTIMES),required=True)
    c.add_argument('--layout')
    c=sub.add_parser('component-subjects')
    c.add_argument('--prepared',required=True)
    c=sub.add_parser('runtime-definition')
    c.add_argument('--runtime',choices=tuple(RUNTIMES),required=True)
    c=sub.add_parser('complete-pair')
    for key in ('root','prepared','scan-root','smoke-root','output'):
        c.add_argument('--'+key,required=True)
    c=sub.add_parser('qualify-unpublished-pair')
    for key in ('root','native','scan-root','output'):
        c.add_argument('--'+key,required=True)
    c.add_argument('--require-clean',action='store_true')
    c=sub.add_parser('observe-unpublished-runtime-scans')
    c.add_argument('--root', type=Path, required=True)
    c.add_argument('--scan-root', type=Path, required=True)
    c=sub.add_parser('verify-changed-base-tags')
    c.add_argument('--root',required=True)
    c.add_argument('--scope',required=True)
    c=sub.add_parser('admission-pair')
    for key in ('root','prepared','scan-root','smoke-root'):
        c.add_argument('--'+key,required=True)
    args=parser.parse_args()
    if args.command=='verify-native':
        expected=source(args.root,os.environ['GITHUB_SHA'],args.runtime)
        record,_,_=observe_native(args.root,args.directory,args.arch,expected,layout=args.layout,attempt=native_attempt(args.arch))
        write(args.output,dict(oci_digest=record['subject']['manifest_digest'],platform=record['subject']['platform']))
    elif args.command=='component-subjects':
        print(canonical(component_subjects(args.prepared)).decode())
    elif args.command=='runtime-definition':
        print(canonical(runtime_definition(args.runtime)).decode())
    elif args.command=='complete-pair':
        complete_runtime_pair(args.root,args.prepared,args.scan_root,args.smoke_root,args.output)
    elif args.command=='qualify-unpublished-pair':
        qualify_unpublished_pair(args.root,args.native,args.scan_root,args.output,require_clean=args.require_clean)
    elif args.command=='observe-unpublished-runtime-scans':
        observe_unpublished_runtime_scans(args.root,args.scan_root)
    elif args.command=='verify-changed-base-tags':
        scope = read(args.scope)
        if scope['mode'] != 'build' or scope['source']['merge'] != os.environ['GITHUB_SHA']:
            raise ValueError('publisher-base-tag-source')
        verify_changed_base_tags(args.root, scope['source']['base'], [entry['path'] for entry in scope['changes']])
    elif args.command=='admission-pair':
        recheck_runtime_pair(args.root,args.prepared,args.scan_root,args.smoke_root)
    elif args.command=='candidate-context':
        context=candidate_context(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
        write(args.output,context)
        with open(os.environ['GITHUB_OUTPUT'],'a') as output:
            output.write('candidate_attempt='+context['run_attempt']+'\nscan_attempt='+context['scan_attempt']+'\n')
    elif args.command=='attestation-filename':print(attestation_filename(args.digest))
    elif args.command=='source-security':admit_source_security(read(args.input),args.commit,evidence_now(),schema=source_security_schema(Path(__file__).resolve().parents[1]))
    elif args.command=='source':write(args.output,source(args.root,args.commit,args.runtime))
    elif args.command=='bases':write(args.output,bases(args.root,args.arch,args.runtime))
    elif args.command=='record':
        arch=native_architecture(args.arch,platform.machine(),os.environ.get('RUNNER_ARCH'))
        src,base=read(args.source),read(args.bases)
        subject,_,_=read_layout(args.layout,arch,src,base)
        version=run(['buildah','--version']).decode().split()[2]
        write(args.output,dict(source=src,subject=subject,bases=base,builder=dict(name='buildah',version=version),
                              runner_architecture=arch,workflow_run_id=os.environ['GITHUB_RUN_ID'],
                              workflow_run_attempt=os.environ['GITHUB_RUN_ATTEMPT']))
    elif args.command=='prepare':
        prepare(args.root,args.native,args.output,args.security,args.runtime)
    else:
        record=read(args.record)
        write(args.output,dict(schema_version='1.0.0',format='SPDX',spdx_digest=digest(Path(args.spdx).read_bytes()),
                               subject=dict(oci_digest=record['subject']['manifest_digest'],platform=record['subject']['platform'])))


if __name__=='__main__':
    try: main()
    except (ValueError,KeyError,TypeError,OSError,json.JSONDecodeError) as error:
        raise SystemExit('FAIL: '+str(error))
