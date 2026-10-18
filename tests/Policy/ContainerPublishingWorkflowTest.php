<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Symfony\Component\Yaml\Yaml;

it('does not use the Laravel database test case', function (): void {
    expect($this)->not->toBeInstanceOf(Tests\TestCase::class);
});

function containerPublishingWorkflowPath(): string
{
    return dirname(__DIR__, 2).'/.github/workflows/publish-container.yml';
}

/** @return list<string> */
function containerPublishingWorkflowFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/.github/workflows', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && ! $file->isLink()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** @param array<array-key, mixed> $node
 * @return list<string>
 */
function containerPublishingWorkflowRunCommands(array $node): array
{
    $commands = [];

    foreach ($node as $key => $value) {
        if ($key === 'run' && is_string($value)) {
            $commands[] = $value;
        }

        if (is_array($value)) {
            array_push($commands, ...containerPublishingWorkflowRunCommands($value));
        }
    }

    return $commands;
}

/** @return array<array-key, mixed> */
function containerPublishingParsedWorkflowFile(string $workflowFile): array
{
    $source = (string) file_get_contents($workflowFile);
    $source = preg_replace('/^---\h*$/m', '', $source, 1) ?? $source;
    $workflow = Yaml::parse($source);

    expect($workflow)->toBeArray();

    return $workflow;
}

/** @return list<string> */
function containerPublishingInvokedRepositoryScripts(): array
{
    $root = dirname(__DIR__, 2);
    $scripts = [];

    foreach (containerPublishingWorkflowFiles() as $workflowFile) {
        $workflow = containerPublishingParsedWorkflowFile($workflowFile);

        foreach (containerPublishingWorkflowRunCommands($workflow) as $command) {
            preg_match_all(
                '~(?<![\w./-])(?:\./)?(?:[\w.-]+/)+[\w.-]+\.(?:php|py|sh)\b~',
                $command,
                $matches,
            );

            foreach ($matches[0] as $path) {
                $absolutePath = $root.'/'.ltrim($path, './');

                if (is_file($absolutePath) && ! is_link($absolutePath)) {
                    $scripts[] = $absolutePath;
                }
            }
        }
    }

    $scripts = array_values(array_unique($scripts));
    sort($scripts);

    return $scripts;
}

/** @return list<string> */
function containerPublishingAutomationFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = containerPublishingWorkflowFiles();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/scripts', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && ! $file->isLink()) {
            $files[] = $file->getPathname();
        }
    }

    array_push($files, ...containerPublishingInvokedRepositoryScripts());
    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/** @return array<string, mixed> */
function containerPublishingWorkflow(): array
{
    $workflow = Yaml::parseFile(containerPublishingWorkflowPath());

    expect($workflow)->toBeArray();

    return $workflow;
}

/** @param array<string, mixed> $job */
function containerPublishingRunScripts(array $job): string
{
    return implode("\n", array_map(
        static fn (array $step): string => (string) ($step['run'] ?? ''),
        $job['steps'],
    ));
}

/** @return list<string> */
function containerPublishingActionNames(array $workflow): array
{
    $names = [];

    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (isset($step['uses'])) {
                $names[] = explode('@', $step['uses'], 2)[0];
            }
        }
    }

    return $names;
}

function containerPublishingForbiddenCommandPattern(): string
{
    return '/(?:\bdocker\h+(?:[a-z-]+\h+)*prune\b|\bdocker\h+(?:push|manifest\h+push|buildx\h+(?:(?:build|bake)\b[^\r\n]*(?:--push\b|(?:--output|-o)(?:=|\h+)type=registry\b)|imagetools\h+create))\b|\b(?:oras|podman)\h+(?:push|cp|copy)\b|\bcrane\h+(?:push|copy)\b|\bskopeo\h+(?:copy|sync)\b|\bregctl\h+(?:image|manifest)\h+(?:copy|put)\b|\bcurl\b[^\r\n]*(?:(?:-X|--request)\h*(?:PUT|POST|PATCH|DELETE)\b|(?:-T|--upload-file|--form|-F)\b))/i';
}

function containerPublishingForbiddenDeletionPattern(): string
{
    return '/(?:\bdocker\h+buildx\h+imagetools\h+rm\b|\boras\h+manifest\h+delete\b|\bcrane\h+delete\b|\bskopeo\h+delete\b|\bregctl\h+(?:(?:image|manifest)\h+(?:delete|rm)|tag\h+rm)\b)/i';
}

function containerPublishingWithoutShellComment(string $command): string
{
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $escaped = false;

    for ($offset = 0, $length = strlen($command); $offset < $length; $offset++) {
        $character = $command[$offset];

        if ($escaped) {
            $escaped = false;

            continue;
        }

        if ($character === '\\' && ! $inSingleQuote) {
            $escaped = true;

            continue;
        }

        if ($character === "'" && ! $inDoubleQuote) {
            $inSingleQuote = ! $inSingleQuote;

            continue;
        }

        if ($character === '"' && ! $inSingleQuote) {
            $inDoubleQuote = ! $inDoubleQuote;

            continue;
        }

        if ($character === '#'
            && ! $inSingleQuote
            && ! $inDoubleQuote
            && ($offset === 0 || str_contains(" \t", $command[$offset - 1]))) {
            return rtrim(substr($command, 0, $offset));
        }
    }

    return $command;
}

function containerPublishingContainsForbiddenDeletion(string $source): bool
{
    $normalized = preg_replace('/\\\\\r?\n\h*/', ' ', $source);
    $commands = preg_split('/\r?\n|&&|\|\||;/', $normalized ?? $source);

    foreach ($commands ?: [] as $command) {
        $command = containerPublishingWithoutShellComment($command);

        if (preg_match(containerPublishingForbiddenDeletionPattern(), $command) === 1) {
            return true;
        }

        if (preg_match('/\bgh\h+api\b/i', $command) !== 1) {
            continue;
        }

        if (preg_match(
            '/\bgh\h+api\b.*?(?:-X(?:=|\h*)|--method(?:=|\h+))DELETE\b/i',
            $command,
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/\bgh\h+api\b.*?\bgraphql\b.*?\bdeletePackageVersion\b/i',
            $command,
        ) === 1) {
            return true;
        }
    }

    return false;
}

function secPalApiImageReferenceIsCanonical(string $reference): bool
{
    return preg_match('/\Aghcr\.io\/secpal\/api@sha256:[0-9a-f]{64}\z/', $reference) === 1;
}

it('gates official publication on source security and native validation', function (): void {
    $w = containerPublishingWorkflow();
    expect($w['on'])->toBe(['push' => ['branches' => ['main']]])
        ->and($w['permissions'])->toBe([])
        ->and($w['jobs']['repository-security']['uses'])->toBe('./.github/workflows/reusable-repository-security.yml')
        ->and($w['jobs']['build']['needs'])->toBe(['repository-security'])
        ->and($w['jobs']['build']['uses'])->toBe('./.github/workflows/container-image.yml')
        ->and($w['jobs']['prepare']['needs'])->toBe(['build', 'repository-security'])
        ->and($w['jobs']['accept']['needs'])->toBe(['prepare', 'attest', 'verify', 'scan', 'repository-security']);
    foreach (['build', 'repository-security'] as $job) {
        expect($w['jobs'][$job]['permissions'])->toBe(['contents' => 'read']);
    }
    foreach (['verify', 'scan'] as $job) {
        expect($w['jobs'][$job]['permissions'])->toBe([
            'contents' => 'read', 'packages' => 'read', 'attestations' => 'read',
        ]);
    }
    foreach (['attest', 'accept'] as $job) {
        expect($w['jobs'][$job]['permissions'])->toBe([
            'contents' => 'read', 'packages' => 'write', 'attestations' => 'write', 'id-token' => 'write',
        ]);
    }
});

it('shares database-free native Buildah builds without PR publication or emulation', function (): void {
    $root = dirname(__DIR__, 2);
    $ci = Yaml::parseFile($root.'/.github/workflows/container-image.yml');
    $build = (string) file_get_contents($root.'/scripts/build-container.sh');
    expect($ci['permissions'])->toBe(['contents' => 'read'])
        ->and($ci['on'])->toHaveKey('workflow_call')
        ->and($ci['on']['pull_request']['paths'])->toContain('Containerfile', '.containerignore', 'tests/Policy/**', 'composer.lock')
        ->and($ci['jobs']['build-and-test']['needs'])->toBe('validate')
        ->and($ci['jobs']['build-and-test']['strategy']['matrix']['include'])->toBe([
            ['arch' => 'amd64', 'runner' => 'ubuntu-24.04'],
            ['arch' => 'arm64', 'runner' => 'ubuntu-24.04-arm'],
        ])
        ->and(containerPublishingRunScripts($ci['jobs']['validate']))
        ->toContain('composer test:container-policy', 'python3 tests/container-publisher.py',
            'hadolint/hadolint:v2.14.0-debian@sha256:', 'koalaman/shellcheck:v0.10.0@sha256:')
        ->and($ci['jobs']['validate'])->not->toHaveKeys(['services', 'env'])
        ->and($build)->toContain('buildah build --format oci', '--timestamp "$epoch"', '--pull=never',
            '--file "$snapshot/Containerfile"', 'prepare-snapshot', '--http-proxy=false',
            'Foreign-architecture emulation is not native evidence', 'SKIP_BUILD=1', 'EXPECTED_ARCH="$arch"');
    foreach (['container-image.yml', 'publish-container.yml'] as $file) {
        $w = Yaml::parseFile($root.'/.github/workflows/'.$file);
        foreach (containerPublishingActionNames($w) as $action) {
            expect($action)->not->toStartWith('docker/');
        }
        expect((string) file_get_contents($root.'/.github/workflows/'.$file))
            ->not->toContain('buildx', 'buildkit', 'setup-qemu', 'binfmt');
    }
    expect($build)->not->toMatch('/\bdocker\s|\bpodman\s+build\b/');
});

it('binds remote manifest config and native runtime content before acceptance', function (): void {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root.'/scripts/publish-container.sh');
    expect($script)->toContain('= SecPal/api', '= push', '= refs/heads/main',
        'container-publisher.py prepare', 'cmp "$prepared/index.json" "$prepared/remote-index.json"',
        'cmp "$prepared/platform-$arch.json" "$prepared/remote-platform-$arch.json"',
        'cmp "$prepared/config-$arch.json" "$prepared/remote-config-$arch.json"',
        'podman pull --authfile "$REGISTRY_AUTH_FILE" "$repository@$manifest"',
        'SKIP_BUILD=1 IMAGE_TAG="$repository@$manifest" EXPECTED_ARCH="$arch" tests/docker/smoke.sh');
    expect((string) file_get_contents($root.'/tests/docker/smoke.sh'))->toContain(
        'podman image inspect --format', 'EXPECTED_ARCH', 'unpack("v", substr($header, 18, 2))',
        'test "$(id -u)" -eq 10001', 'test "$(id -g)" -eq 10001',
        'DB_SSLMODE=verify-full', "POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256'",
        'assert_http /health/live 200', 'assert_http /health/ready 200', 'podman stop --time 10',
    )->not->toContain('docker run', 'docker build', 'valkey_image=');
    expect(secPalApiImageReferenceIsCanonical('ghcr.io/secpal/api@sha256:'.str_repeat('a', 64)))->toBeTrue()
        ->and(secPalApiImageReferenceIsCanonical('ghcr.io/secpal/api:build-example-42-1'))->toBeFalse();
});

it('keeps Syft authoritative and scans immutable artifacts independently', function (): void {
    $root = dirname(__DIR__, 2);
    expect((string) file_get_contents($root.'/scripts/build-container.sh'))
        ->toContain('"$tools/syft" scan', '"oci-dir:$output/image"', 'spdx-json=', 'verify-syft');
    expect((string) file_get_contents($root.'/scripts/scan-container-artifact.sh'))->toContain(
        '"$tools/grype" --config "$cache/config.yaml" "sbom:$output/sbom/syft.spdx.json"',
        '"$tools/trivy" --config "$cache/config.yaml" image', '"$registry@$manifest"', '--platform "linux/$arch"',
        'secpal-vulnerability-reevaluation.py', 'secpal-vulnerability-policy.py',
        'policies/vulnerability-triage-v1.json', "policy['gate_state']!='CLEAN'",
    )->not->toContain('--ignore-unfixed', '--severity ', '--exit-code 0');
    expect((string) file_get_contents($root.'/scripts/container-tools.sh'))->toContain(
        'sha256sum --check --strict', 'syft_1.54.1_linux_', 'grype_0.118.0_linux_',
        'trivy_0.74.0_Linux-64bit', 'oras_1.3.4_linux_amd64');
});

it('attests index platforms SBOMs and build evidence through exact GitHub identities', function (): void {
    $root = dirname(__DIR__, 2);
    $w = containerPublishingWorkflow();
    $subjects = [];
    foreach ($w['jobs']['attest']['steps'] as $step) {
        if (str_starts_with($step['uses'] ?? '', 'actions/attest@')) {
            $subjects[] = $step['with']['subject-digest'];
            expect($step['with']['push-to-registry'])->toBeTrue()
                ->and($step['with']['create-storage-record'])->toBeFalse();
        }
    }
    expect($subjects)->toHaveCount(5);
    foreach (['index_digest', 'amd64_digest', 'arm64_digest', 'amd64_sbom_digest', 'arm64_sbom_digest'] as $key) {
        expect(implode("\n", $subjects))->toContain('needs.prepare.outputs.'.$key);
    }
    foreach (['publish-container.sh', 'scan-container-artifact.sh'] as $name) {
        expect((string) file_get_contents($root.'/scripts/'.$name))->toContain(
            '--bundle-from-oci', '--repo SecPal/api',
            '--signer-workflow SecPal/api/.github/workflows/publish-container.yml',
            '--signer-digest "$GITHUB_SHA"', '--source-ref refs/heads/main',
            '--source-digest "$GITHUB_SHA"', '--deny-self-hosted-runners');
    }
    expect(containerPublishingRunScripts($w['jobs']['accept']))->toContain(
        'publish-container.sh evidence', 'gh attestation verify',
        'publish-container.sh retain-evidence', 'publish-container.sh accept');
});

it('writes one discovery pointer only after verified acceptance and retains tags', function (): void {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root.'/scripts/publish-container.sh');
    $accept = substr($script, strpos($script, "\n  accept)"));
    expect(substr_count($script, '"$tools/oras" tag '))->toBe(1)
        ->and($accept)->toContain('published_tag="build-$GITHUB_SHA-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"',
            'verify_attestation "$evidence_digest"', 'cmp "$prepared/index.json" "$prepared/accepted-index.json"',
            'cmp "$prepared/index.json" "$prepared/discovery-index.json"',
            'The discovery tag is not a deployment or trust reference.')
        ->and(strpos($accept, 'verify_attestation "$evidence_digest"'))->toBeLessThan(strpos($accept, '"$tools/oras" tag '))
        ->and($script)->not->toContain(':latest', ':main', 'crane copy');
    expect((string) file_get_contents($root.'/docs/containers.md'))->toContain(
        '## Run-tag retention', 'GHCR does not provide a proven tag-only deletion operation',
        'No publisher job or scheduled workflow has cleanup authority',
        'Interrupted, repeated, and concurrent runs do not share a discovery tag');
});

it('pins actions and disables persisted checkout credentials', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['container-image.yml', 'publish-container.yml'] as $file) {
        $w = Yaml::parseFile($root.'/.github/workflows/'.$file);
        foreach ($w['jobs'] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (! isset($step['uses'])) {
                    continue;
                }
                expect($step['uses'])->toMatch('/\A[^@\s]+@[0-9a-f]{40}\z/');
                if (str_starts_with($step['uses'], 'actions/checkout@')) {
                    expect($step['with']['persist-credentials'])->toBeFalse();
                }
            }
        }
    }
});

it('forbids registry deletion across workflows and repository scripts', function (): void {
    foreach (containerPublishingAutomationFiles() as $file) {
        expect(containerPublishingContainsForbiddenDeletion((string) file_get_contents($file)), $file)->toBeFalse();
    }
});

it('recognizes prohibited registry writes and every Docker prune family', function (string $command): void {
    expect($command)->toMatch(containerPublishingForbiddenCommandPattern());
})->with([
    'docker push ghcr.io/secpal/api:test',
    'docker manifest push ghcr.io/secpal/api:test',
    'docker buildx build --push --tag registry.example/secpal/api:test .',
    'docker buildx build --output type=registry,name=registry.example/secpal/api:test .',
    'docker buildx build -o type=registry,name=registry.example/secpal/api:test .',
    'docker buildx bake release --push',
    'docker buildx imagetools create --tag registry.example/secpal/api:test source',
    'oras push registry.example/secpal/api:test image.tar',
    'oras copy ghcr.io/secpal/api:test registry.example/secpal/api:test',
    'crane push image.tar registry.example/secpal/api:test',
    'crane copy source destination',
    'skopeo copy source destination',
    'regctl manifest put registry.example/secpal/api:test',
    'regctl image copy source destination',
    'podman push registry.example/secpal/api:test',
    'curl --request PUT https://registry.example/v2/api/manifests/test',
    'curl -X POST https://registry.example/v2/api/blobs/uploads/',
    'curl --request PATCH https://registry.example/v2/api/blobs/uploads/id',
    'curl -X DELETE https://registry.example/v2/api/manifests/test',
    'docker container prune --force',
    'docker image prune --force',
    'docker network prune --force',
    'docker system prune --force',
    'docker volume prune --force',
    'docker builder prune --force',
    'docker buildx prune --force',
]);

it('recognizes prohibited registry deletion commands', function (string $command): void {
    expect(containerPublishingContainsForbiddenDeletion($command))->toBeTrue();
})->with([
    'gh api --method DELETE /orgs/SecPal/packages/container/api/versions/123',
    'gh api --method=DELETE /orgs/SecPal/packages/container/api/versions/123',
    'gh api -X DELETE /orgs/SecPal/packages/container/api/versions/123',
    'gh api -XDELETE /orgs/SecPal/packages/container/api/versions/123',
    "gh api \\\n  --method DELETE /orgs/SecPal/packages/container/api/versions/123",
    "gh api --method \\\n  DELETE /orgs/SecPal/packages/container/api/versions/123",
    "gh api \\\n  graphql -f query='mutation { deletePackageVersion(input: {}) { success } }'",
    "gh api graphql -f query='mutation { deletePackageVersion(input: {}) { success } }'",
    "gh api graphql -f query='mutation { remove: deletePackageVersion(input: {}) { success } }'",
    'oras manifest delete ghcr.io/secpal/api:build-deadbeef-1-1',
    'crane delete ghcr.io/secpal/api:build-deadbeef-1-1',
    'skopeo delete docker://ghcr.io/secpal/api:build-deadbeef-1-1',
    'regctl manifest delete ghcr.io/secpal/api:build-deadbeef-1-1',
    'regctl image rm ghcr.io/secpal/api:build-deadbeef-1-1',
    'regctl tag rm ghcr.io/secpal/api:build-deadbeef-1-1',
    'docker buildx imagetools rm ghcr.io/secpal/api:build-deadbeef-1-1',
]);

it('recognizes a prohibited folded YAML registry deletion command', function (): void {
    $workflow = Yaml::parse(<<<'YAML'
        steps:
          - run: >-
              gh api
              --method DELETE
              /orgs/SecPal/packages/container/api/versions/123
        YAML);

    expect($workflow['steps'][0]['run'])
        ->not->toBeNull();
    expect(containerPublishingContainsForbiddenDeletion($workflow['steps'][0]['run']))
        ->toBeTrue();
});

it('bounds GraphQL deletion detection to the gh api command', function (): void {
    $commands = <<<'SHELL'
        gh api graphql -f query='query { viewer { login } }'
        printf '%s\n' 'deletePackageVersion is forbidden by policy'
        SHELL;

    expect(containerPublishingContainsForbiddenDeletion($commands))->toBeFalse();
});

it('ignores a prohibited GraphQL field named only in an inline shell comment', function (): void {
    $command = "gh api graphql -f query='query { viewer { login } }' "
        .'# deletePackageVersion is forbidden by policy';

    expect(containerPublishingContainsForbiddenDeletion($command))->toBeFalse();
});

it('ignores a prohibited GraphQL field named only in a folded shell comment', function (): void {
    $workflow = Yaml::parse(<<<'YAML'
        steps:
          - run: >-
              gh api graphql -f query='query { viewer { login } }'
              # deletePackageVersion is forbidden by policy
        YAML);

    expect(containerPublishingContainsForbiddenDeletion($workflow['steps'][0]['run']))
        ->toBeFalse();
});

it('preserves hash characters inside a quoted destructive GraphQL query', function (): void {
    $command = <<<'SHELL'
        gh api graphql -f query='mutation { audit(value: "#") deletePackageVersion(input: {}) { success } }'
        SHELL;

    expect(containerPublishingContainsForbiddenDeletion($command))->toBeTrue();
});
