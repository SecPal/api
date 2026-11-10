#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Read-only applicability and replay in the existing native CI, never release admission."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import runpy
import shutil
import stat
import subprocess
import tempfile
import zipfile

ROLES = ('php-fpm', 'nginx')
POLICY = '.github/scripts/native-ci.py'
PREFIX = 'repos/SecPal/api'


def require(condition):
    if not condition:
        raise ValueError('Native CI scope or qualified-image binding is incomplete')


def run(*args, cwd=None, env=None):
    return subprocess.check_output(args, cwd=cwd, env=env, timeout=180)


def git(root, *args):
    return run('git', '--no-replace-objects', *args, cwd=root)


def digest(data):
    return 'sha256:'+hashlib.sha256(data).hexdigest()


def write(path, value):
    Path(path).write_text(json.dumps(value, sort_keys=True, separators=(',', ':'))+'\n')


def api(path):
    return json.loads(run('gh', 'api', '--method', 'GET', '--hostname', 'github.com', PREFIX+'/'+path))


def successful_runs():
    pages = json.loads(run('gh', 'api', '--paginate', '--slurp', '--hostname', 'github.com',
                           PREFIX+'/actions/workflows/publish-container.yml/runs?event=workflow_dispatch&branch=main&status=success&per_page=100'))
    runs = [r for page in pages for r in page['workflow_runs']]
    require(pages and all(page['total_count'] == len(runs) for page in pages))
    return runs


def changes(root, base, target):
    raw = git(root, 'diff', '--raw', '-z', '--no-renames', '--no-abbrev', '--no-ext-diff',
              '--no-textconv', '--ignore-submodules=none', base, target, '--')
    fields = raw.split(b'\0')
    require(fields.pop() == b'' and len(fields) % 2 == 0)
    result = []
    for index in range(0, len(fields), 2):
        meta = fields[index].decode('ascii').split()
        path = fields[index+1].decode('utf8')
        require(len(meta) == 5 and meta[0].startswith(':') and meta[4] in ('A', 'D', 'M'))
        modes = (meta[0][1:], meta[1])
        require(all(m in ('000000', '100644', '100755') for m in modes))
        require(all(re.fullmatch('[0-9a-f]{40}', value) for value in meta[2:4]))
        require(path == str(PurePosixPath(path)) and not path.startswith('/')
                and '..' not in PurePosixPath(path).parts and '\n' not in path and '\r' not in path)
        result.append(dict(path=path, status=meta[4], old_mode=modes[0], new_mode=modes[1],
                           old_blob=meta[2], new_blob=meta[3]))
    return raw, result


def excluded_context(root, commit, entries):
    # Conservatively compare the whole tracked build context. Only these actual
    # .containerignore exclusions may be omitted; do not emulate its full grammar.
    if any(not (c['path'].startswith(('tests/', '.github/')) or c['path'] == 'CHANGELOG.md') for c in entries):
        return False
    patterns = git(root, 'show', commit+':.containerignore').decode().splitlines()
    patterns = [p.strip() for p in patterns if p.strip() and not p.lstrip().startswith('#')]
    require({'tests', '.github', 'CHANGELOG.md'} <= set(patterns))
    for pattern in patterns:
        if pattern.startswith('!'):
            included = pattern[1:].lstrip('/')
            require(not any(c in included for c in '*?[\\')
                    and included.split('/')[0] not in ('tests', '.github', 'CHANGELOG.md'))
    return True


def scope(root, base, head, merge, *, bootstrap=False):
    require(all(re.fullmatch('[0-9a-f]{40}', x) for x in (base, head, merge)))
    require(git(root, 'rev-parse', '--is-shallow-repository').strip() == b'false')
    require(git(root, 'rev-parse', 'HEAD').decode().strip() == merge)
    require(not git(root, 'status', '--porcelain', '--untracked-files=all').strip())
    require(git(root, 'rev-list', '--parents', '-n', '1', merge).decode().split() == [merge, base, head])
    raw, entries = changes(root, base, merge)
    require(entries)
    mode = 'build'
    if excluded_context(root, base, entries):
        mode = 'reuse'
        if not bootstrap and all(c['path'].startswith('tests/')
                                 and not c['path'].startswith('tests/docker/')
                                 and (c['old_mode'] == c['new_mode'] or
                                      {c['old_mode'], c['new_mode']} <= {'000000', '100644'}) for c in entries):
            mode = 'not-applicable'
    return dict(schema_version='secpal-native-ci-v1', mode=mode,
                source=dict(base=base, head=head, merge=merge), changes=entries,
                diff_sha256=digest(raw),
                policy_source='bootstrap' if bootstrap else 'protected-base')


def current_scope(root, bootstrap):
    require(os.environ['GITHUB_REPOSITORY'] == 'SecPal/api')
    if os.environ['GITHUB_EVENT_NAME'] != 'pull_request':
        require(os.environ['GITHUB_EVENT_NAME'] in ('push', 'workflow_dispatch')
                and os.environ['GITHUB_REF'] == 'refs/heads/main')
        return dict(schema_version='secpal-native-ci-v1', mode='build')
    event = json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text())['pull_request']
    require(event['base']['repo']['full_name'] == 'SecPal/api' and event['base']['ref'] == 'main')
    base = event['base']['sha']
    require(api('git/ref/heads/main')['object']['sha'] == base)
    protected = subprocess.run(['git', '--no-replace-objects', 'show', base+':'+POLICY],
                               cwd=root, capture_output=True)
    require(bootstrap == (protected.returncode != 0))
    if not bootstrap:
        require(Path(__file__).read_bytes() == protected.stdout)
    return scope(root, base, event['head']['sha'], os.environ['GITHUB_SHA'], bootstrap=bootstrap)


def publisher(root, proof_path):
    proof = json.loads(Path(proof_path).read_text())
    commit = proof.get('source', {}).get('base', os.environ.get('GITHUB_SHA'))
    path = Path(proof_path).parent/'publisher.py'
    require(path.read_bytes() == git(root, 'show', commit+':scripts/container-publisher.py'))
    return runpy.run_path(str(path))


def archive(artifact, destination):
    require(type(artifact['id']) is int and artifact['id'] > 0 and artifact['expired'] is False
            and re.fullmatch('sha256:[0-9a-f]{64}', artifact['digest']))
    with open(destination, 'wb') as output:
        subprocess.run(['gh', 'api', '--allow-escape-sequences', '--hostname', 'github.com',
                        PREFIX+'/actions/artifacts/'+str(artifact['id'])+'/zip'],
                       stdout=output, check=True, timeout=600)
    with open(destination, 'rb') as data:
        require('sha256:'+hashlib.file_digest(data, 'sha256').hexdigest() == artifact['digest'])
    return checked_zip(destination)


def checked_zip(path):
    z = zipfile.ZipFile(path)
    names = z.namelist()
    try:
        require(len(names) == len(set(names)) and len(names) <= 100000
                and sum(i.file_size for i in z.infolist()) <= 4*1024**3)
        for info in z.infolist():
            name = info.filename.rstrip('/')
            require(name == str(PurePosixPath(name)) and not name.startswith('/')
                    and '..' not in PurePosixPath(name).parts and '\\' not in name
                    and stat.S_IFMT(info.external_attr >> 16) in (0, stat.S_IFREG, stat.S_IFDIR))
        return z
    except BaseException:
        z.close()
        raise


def origin(root, proof_path, run_id):
    proof = json.loads(Path(proof_path).read_text())
    pub = publisher(root, proof_path)
    producing = api('actions/runs/'+str(run_id))
    commit = producing['head_sha']
    require(producing['status'] == 'completed' and producing['conclusion'] == 'success'
            and producing['head_branch'] == 'main' and producing['event'] == 'workflow_dispatch')
    require(subprocess.run(['git', 'merge-base', '--is-ancestor', commit, proof['source']['base']],
                           cwd=root, capture_output=True).returncode == 0)
    _, delta = changes(root, commit, proof['source']['merge'])
    require(excluded_context(root, commit, delta))
    context = pub['candidate_context'](commit, str(run_id), str(producing['run_attempt']), runtime_set='pair')
    inventory = api('actions/runs/'+str(run_id)+'/artifacts?per_page=100')
    require(inventory['total_count'] == len(inventory['artifacts']))

    def select(name):
        selected = [a for a in inventory['artifacts'] if a['name'] == name]
        require(len(selected) == 1 and selected[0]['expired'] is False)
        return selected[0]

    accepted = select(f"api-accepted-{run_id}-{producing['run_attempt']}")
    with tempfile.TemporaryDirectory() as temp:
        with archive(accepted, Path(temp)/'accepted.zip') as z:
            pair = json.loads(z.read('runtime-pair.json'))
            evidence = {r: json.loads(z.read(r+'/build-evidence.json')) for r in ROLES}
            require(pub['assemble_runtime_pair'](evidence, commit, pair['generated_at']) == pair)
            records = {r: {a: json.loads(z.read(r+'/record-'+a+'.json')) for a in ('amd64', 'arm64')} for r in ROLES}
    return dict(context=context, accepted=accepted,
                artifacts={a: select(f"api-native-{a}-{run_id}-{context['run_attempt']}") for a in ('amd64', 'arm64')},
                records=records)


def verify(root, proof_path):
    proof = json.loads(Path(proof_path).read_text())
    expected = current_scope(root, proof.get('policy_source') == 'bootstrap')
    require(all(proof.get(k) == v for k, v in expected.items()))
    if proof['mode'] == 'reuse':
        observed = origin(root, proof_path, proof['baseline']['context']['run_id'])
        require(observed == proof['baseline'])
    return proof


def base_checkout(root, commit):
    destination = root/'.context/ci-runtime'
    if not destination.exists():
        subprocess.run(['git', 'worktree', 'add', '--detach', str(destination), commit], cwd=root, check=True)
    require(git(destination, 'rev-parse', 'HEAD').decode().strip() == commit)
    return destination


def observe(root, proof_path, native, arch):
    proof = json.loads(Path(proof_path).read_text())
    baseline = proof['baseline']
    context = baseline['context']
    pub = publisher(root, proof_path)
    base = base_checkout(root, context['source_commit'])
    observed = {}
    for role in (*ROLES, 'legacy'):
        folder = native/role
        expected = pub['source'](base, context['source_commit'], role)
        bases = pub['bases'](base, arch, role)
        subject, _, _ = pub['read_layout'](folder/'image', arch, expected, bases)
        record = json.loads((folder/'record.json').read_text())
        require(record['source'] == expected and record['subject'] == subject and record['bases'] == bases
                and record['runner_architecture'] == arch and record['workflow_run_id'] == context['run_id']
                and record['workflow_run_attempt'] == context['run_attempt'])
        if role in ROLES:
            require(record == baseline['records'][role][arch])
        observed[role] = subject['manifest_digest']
    return observed


def restore(root, proof_path, output, arch):
    proof = verify(root, proof_path)
    require(proof['mode'] == 'reuse')
    pub = publisher(root, proof_path)
    pub['native_architecture'](arch, platform.machine(), os.environ['RUNNER_ARCH'])
    require(not any('enabled' in p.read_text().splitlines()
                    for p in Path('/proc/sys/fs/binfmt_misc').glob('qemu-*') if p.is_file()))
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as temp:
        with archive(proof['baseline']['artifacts'][arch], Path(temp)/'native.zip') as z:
            z.extractall(output)
    subjects = observe(root, proof_path, output, arch)
    images = {r: 'localhost/secpal-api:ci-reuse-'+r+'-'+arch for r in subjects}
    configs = {r: json.loads((output/r/'record.json').read_text())['subject']['config_digest'] for r in subjects}
    for role, image in images.items():
        image_id = run('podman', 'pull', '--quiet', 'oci:'+str(output/role/'image')+':api').decode().strip()
        require(image_id.removeprefix('sha256:') == configs[role].removeprefix('sha256:'))
        subprocess.run(['podman', 'tag', image_id, image], check=True)
    env = {k: v for k, v in os.environ.items() if k not in ('GH_TOKEN', 'GITHUB_TOKEN')}
    env.update(SKIP_BUILD='1', EXPECTED_ARCH=arch)
    for role in ROLES:
        subprocess.run(['tests/docker/runtime-component.sh', role, images[role], arch], cwd=root, env=env, check=True)
    subprocess.run(['tests/docker/smoke.sh'], cwd=root, check=True,
                   env=dict(env, RUNTIME_SET='pair', PHP_FPM_IMAGE=images['php-fpm'], NGINX_IMAGE=images['nginx']))
    subprocess.run(['tests/docker/smoke.sh'], cwd=root, check=True,
                   env=dict(env, RUNTIME_SET='legacy', IMAGE_TAG=images['legacy']))
    for role, image in images.items():
        image_id = run('podman', 'image', 'inspect', '--format', '{{.Id}}', image).decode().strip()
        require(image_id.removeprefix('sha256:') == configs[role].removeprefix('sha256:'))
    write(output/'ci-smoke.json', dict(subjects=subjects, arch=arch, result='SUCCEEDED',
                                     run_id=os.environ['GITHUB_RUN_ID'], attempt=os.environ['GITHUB_RUN_ATTEMPT'],
                                     scope_sha256=digest(Path(proof_path).read_bytes())))


def scan(root, proof_path, native, output):
    proof = verify(root, proof_path)
    require(proof['mode'] == 'reuse')
    context = proof['baseline']['context']
    base = base_checkout(root, context['source_commit'])
    (base/'.context').mkdir(exist_ok=True)
    for name in ('publisher-governance', 'reviewed-vex'):
        (base/'.context'/name).symlink_to(root/'.context'/name, target_is_directory=True)
    current_run, current_attempt = os.environ['GITHUB_RUN_ID'], os.environ['GITHUB_RUN_ATTEMPT']
    subjects = {}
    for arch in ('amd64', 'arm64'):
        folder = native/f'api-native-{arch}-{current_run}-{os.environ["API_NATIVE_"+arch.upper()+"_ATTEMPT"]}'
        subjects[arch] = observe(root, proof_path, folder, arch)
        smoke = json.loads((folder/'ci-smoke.json').read_text())
        require(smoke == dict(subjects=subjects[arch], arch=arch, result='SUCCEEDED', run_id=current_run,
                              attempt=os.environ['API_NATIVE_'+arch.upper()+'_ATTEMPT'],
                              scope_sha256=digest(Path(proof_path).read_bytes())))
        old_name = f"api-native-{arch}-{context['run_id']}-{context['run_attempt']}"
        old_path = base/'.context/native'/old_name
        old_path.parent.mkdir(exist_ok=True)
        old_path.symlink_to(folder, target_is_directory=True)
    # The existing scanner observes the ORIGINAL producer context. Its actual
    # scan timestamps/databases are fresh; the new CI receipt below identifies
    # this verification run separately. No producer record is rewritten.
    subprocess.run(['scripts/scan-container-artifact.sh', 'local'], cwd=base, check=True,
                   env=dict(os.environ, GITHUB_SHA=context['source_commit'], GITHUB_RUN_ID=context['run_id'],
                            GITHUB_RUN_ATTEMPT=context['run_attempt'], API_RUNTIME_SET='pair',
                            API_NATIVE_AMD64_ATTEMPT=context['run_attempt'], API_NATIVE_ARM64_ATTEMPT=context['run_attempt']))
    pub = publisher(root, proof_path)
    policies = {}
    for role in ROLES:
        for arch in ('amd64', 'arm64'):
            folder = base/'.context/scan'/role/arch
            evaluation = json.loads((folder/'evaluation.json').read_text())
            require(evaluation['subject'] == dict(platform='linux/'+arch, oci_digest=subjects[arch][role]))
            policy = pub['observe_policy'](base, folder, pub['evidence_now']())
            require(policy['gate_state'] == 'CLEAN' and policy['evidence_health']['state'] == 'HEALTHY')
            policies[role+'/'+arch] = digest(json.dumps(policy, sort_keys=True, separators=(',', ':')).encode())
    verify(root, proof_path)
    shutil.copytree(base/'.context/scan', output, dirs_exist_ok=True)
    write(output/'requalified-existing-images.json', dict(
        schema_version='secpal-native-ci-v1', qualification='SUCCEEDED', runtime_source=context['source_commit'],
        scope_sha256=digest(Path(proof_path).read_bytes()), subjects=subjects, policies=policies,
        producer=context, verification=dict(run_id=current_run, attempt=current_attempt)))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=('plan', 'verify', 'restore', 'scan', 'not-applicable'))
    parser.add_argument('--root', type=Path, default=Path.cwd())
    parser.add_argument('--proof', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--arch', choices=('amd64', 'arm64'))
    parser.add_argument('--bootstrap', action='store_true')
    args = parser.parse_args()
    root = args.root.resolve()
    if args.command == 'plan':
        proof = current_scope(root, args.bootstrap)
        write(args.proof, proof)
        if proof['mode'] == 'reuse':
            for candidate in successful_runs():
                commit = candidate['head_sha']
                if subprocess.run(['git', 'merge-base', '--is-ancestor', commit, proof['source']['base']], cwd=root).returncode:
                    continue
                _, delta = changes(root, commit, proof['source']['merge'])
                if excluded_context(root, commit, delta):
                    proof['baseline'] = origin(root, args.proof, candidate['id'])
                    break
            require('baseline' in proof)
            write(args.proof, proof)
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            output.write('mode='+proof['mode']+'\n')
    elif args.command == 'verify':
        print(verify(root, args.proof)['mode'])
    elif args.command == 'not-applicable':
        proof = verify(root, args.proof)
        require(proof['mode'] == 'not-applicable' and proof['policy_source'] == 'protected-base')
        args.output.mkdir(parents=True, exist_ok=True)
        write(args.output/'not-applicable.json', proof)
        print('Native OCI qualification: NOT_APPLICABLE; verified test-only Git diff; no image or scan issued.')
    elif args.command == 'restore':
        restore(root, args.proof, args.output, args.arch)
    else:
        scan(root, args.proof, root/'.context/native', args.output)


if __name__ == '__main__':
    main()
