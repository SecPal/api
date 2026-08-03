<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Symfony\Component\Yaml\Yaml;

it('does not use the Laravel database test case', function (): void {
    expect($this)->not->toBeInstanceOf(Tests\TestCase::class);
});

function containerPublishingWorkflowPath(): string
{
    return dirname(__DIR__, 2).'/.github/workflows/publish-container.yml';
}

/** @return array<string, mixed> */
function containerPublishingWorkflow(): array
{
    $workflow = Yaml::parseFile(containerPublishingWorkflowPath());

    expect($workflow)->toBeArray();

    return $workflow;
}

/** @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function containerPublishingStep(array $job, string $id): array
{
    foreach ($job['steps'] as $step) {
        if (($step['id'] ?? null) === $id) {
            return $step;
        }
    }

    throw new RuntimeException("Missing workflow step: {$id}");
}

/** @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function containerPublishingNamedStep(array $job, string $name): array
{
    foreach ($job['steps'] as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    throw new RuntimeException("Missing workflow step: {$name}");
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
        foreach ($job['steps'] as $step) {
            if (isset($step['uses'])) {
                $names[] = explode('@', $step['uses'], 2)[0];
            }
        }
    }

    return $names;
}

function containerPublishingContractContents(): string
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['.github', 'tests', 'scripts', 'docs'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $files[] = $file->getPathname();
            }
        }
    }

    $files[] = $root.'/README.md';
    $files[] = $root.'/CHANGELOG.md';
    sort($files);

    return implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        $files,
    ));
}

/** @return list<string> */
function containerPolicyComposerScript(): array
{
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer)->toBeArray();

    return $composer['scripts']['test:container-policy'] ?? [];
}

function containerPublishingForbiddenCommandPattern(): string
{
    return '/(?:\bdocker\h+(?:[a-z-]+\h+)*prune\b|\bdocker\h+(?:push|manifest\h+push|buildx\h+(?:(?:build|bake)\b[^\r\n]*(?:--push\b|(?:--output|-o)(?:=|\h+)type=registry\b)|imagetools\h+create))\b|\b(?:oras|podman)\h+(?:push|cp|copy)\b|\bcrane\h+(?:push|copy)\b|\bskopeo\h+(?:copy|sync)\b|\bregctl\h+(?:image|manifest)\h+(?:copy|put)\b|\bcurl\b[^\r\n]*(?:(?:-X|--request)\h*(?:PUT|POST|PATCH|DELETE)\b|(?:-T|--upload-file|--form|-F)\b))/i';
}

function secPalApiImageReferenceIsCanonical(string $reference): bool
{
    return preg_match('/\Aghcr\.io\/secpal\/api@sha256:[0-9a-f]{64}\z/', $reference) === 1;
}

it('defines a main-only publish workflow with isolated least-privilege jobs', function (): void {
    $workflow = containerPublishingWorkflow();
    $validate = $workflow['jobs']['validate'];
    $publish = $workflow['jobs']['publish'];
    $verify = $workflow['jobs']['verify'];
    $attest = $workflow['jobs']['attest'];

    expect($workflow['name'])->toBe('Publish Container')
        ->and($workflow['on'])->toBe(['push' => ['branches' => ['main']]])
        ->and($workflow['permissions'])->toBe([])
        ->and($workflow['concurrency'])->toBe([
            'group' => 'publish-container-${{ github.repository }}-${{ github.sha }}',
            'cancel-in-progress' => false,
        ])->and($workflow['env'])->toBe([
            'GHCR_HOST' => 'ghcr.io',
            'GHCR_REPOSITORY_PATH' => 'secpal/api',
            'CANONICAL_IMAGE' => 'ghcr.io/secpal/api',
        ])->and(array_keys($workflow['jobs']))->toBe(['validate', 'publish', 'verify', 'attest'])
        ->and($validate['permissions'])->toBe(['contents' => 'read'])
        ->and($publish['permissions'])->toBe(['contents' => 'read', 'packages' => 'write'])
        ->and($verify['permissions'])->toBe(['contents' => 'read', 'packages' => 'read'])
        ->and($attest['permissions'])->toBe([
            'contents' => 'read',
            'packages' => 'write',
            'attestations' => 'write',
            'id-token' => 'write',
        ]);

    foreach ([$validate, $publish, $verify, $attest] as $job) {
        expect($job['runs-on'])->toBe('ubuntu-latest')
            ->and($job['timeout-minutes'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('keeps static container policy validation independent from PostgreSQL', function (): void {
    $root = dirname(__DIR__, 2);
    $publishWorkflow = containerPublishingWorkflow();
    $pullRequestWorkflow = Yaml::parseFile($root.'/.github/workflows/container-image.yml');
    $publishValidate = $publishWorkflow['jobs']['validate'];
    $pullRequestValidate = $pullRequestWorkflow['jobs']['build-and-test'];
    $definitions = json_encode([$publishWorkflow, $pullRequestWorkflow], JSON_THROW_ON_ERROR);

    expect(containerPolicyComposerScript())->toBe([
        'pest --no-coverage tests/Policy/ContainerImageDefinitionTest.php tests/Policy/ContainerPublishingWorkflowTest.php',
    ])->and($publishValidate)->not->toHaveKeys(['services', 'env'])
        ->and($pullRequestValidate)->not->toHaveKeys(['services', 'env'])
        ->and(containerPublishingRunScripts($publishValidate))
        ->toContain('composer test:container-policy')
        ->not->toContain('php artisan test', 'tests/docker/smoke.sh', 'docker login')
        ->and(containerPublishingRunScripts($pullRequestValidate))
        ->toContain('composer test:container-policy', 'tests/docker/smoke.sh')
        ->and($definitions)->not->toMatch('/DB_[A-Z_]+/');
});

it('keeps validation tools pinned and promotion-free', function (): void {
    $root = dirname(__DIR__, 2);
    $publish = containerPublishingWorkflow()['jobs']['validate'];
    $pullRequest = Yaml::parseFile($root.'/.github/workflows/container-image.yml')['jobs']['build-and-test'];

    foreach ([$publish, $pullRequest] as $job) {
        $scripts = containerPublishingRunScripts($job);
        expect($scripts)
            ->toContain('hadolint/hadolint:v2.14.0-debian@sha256:')
            ->toContain('koalaman/shellcheck:v0.10.0@sha256:')
            ->toContain('docker/healthchecks/http-live.sh', 'tests/docker/smoke.sh')
            ->not->toContain(
                'promote-ghcr'.'-index',
                'tests/container/',
                'tests/fixtures/',
                'Test GHCR index promotion contract',
            );
    }
});

it('publishes every workflow run under a unique non-canonical discovery tag', function (): void {
    $publish = containerPublishingWorkflow()['jobs']['publish'];
    $publishedTag = containerPublishingStep($publish, 'published_tag');
    $build = containerPublishingStep($publish, 'build');

    expect($publishedTag['name'])->toBe('Resolve unique published tag')
        ->and($publishedTag['run'])->toContain(
            'printf \'tag=build-%s-%s-%s\\n\'',
            '"$GITHUB_SHA" "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT"',
        )->and($build)->not->toHaveKey('if')
        ->and($build['with']['tags'])->toBe(
            '${{ env.CANONICAL_IMAGE }}:${{ steps.published_tag.outputs.tag }}',
        )->and($publish['outputs'])->toBe([
            'image_digest' => '${{ steps.build.outputs.digest }}',
            'image_created' => '${{ steps.metadata.outputs.created }}',
            'published_tag' => '${{ steps.published_tag.outputs.tag }}',
        ]);
});

it('uses the OCI index digest as the only canonical image identity', function (): void {
    $workflow = (string) file_get_contents(containerPublishingWorkflowPath());

    expect($workflow)
        ->toContain('DIGEST_REF="${CANONICAL_IMAGE}@${IMAGE_DIGEST}"')
        ->not->toContain(
            'FINAL_REF=',
            ':sha-${GITHUB_SHA}',
            ':latest',
            ':main',
            'semver',
        )->and(secPalApiImageReferenceIsCanonical(
            'ghcr.io/secpal/api@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        ))->toBeTrue()
        ->and(secPalApiImageReferenceIsCanonical(
            'ghcr.io/secpal/api:build-0123456789abcdef0123456789abcdef01234567-123-1',
        ))->toBeFalse();
});

it('never promotes or conditionally creates a stable registry tag', function (): void {
    $root = dirname(__DIR__, 2);
    $activeFiles = containerPublishingContractContents();

    $promotionScript = 'promote-ghcr'.'-index.sh';
    $promotionContract = 'promote-ghcr'.'-index-contract.sh';
    $fakeRegistry = 'fake-ghcr'.'-curl.sh';

    expect(is_file($root.'/scripts/'.$promotionScript))->toBeFalse()
        ->and(is_file($root.'/tests/container/'.$promotionContract))->toBeFalse()
        ->and(is_file($root.'/tests/fixtures/'.$fakeRegistry))->toBeFalse()
        ->and($activeFiles)->not->toContain(
            'If-None'.'-Match',
            'promote-ghcr'.'-index',
            'fake-ghcr'.'-curl',
            'Promote verified'.' candidate',
            'final SHA'.' tag',
            'candidate-'.'${{',
        );
});

it('verifies the workflow-built digest before attesting it', function (): void {
    $workflow = containerPublishingWorkflow();
    $verify = $workflow['jobs']['verify'];
    $attest = $workflow['jobs']['attest'];

    expect($workflow['jobs']['publish']['outputs']['image_digest'])
        ->toBe('${{ steps.build.outputs.digest }}')
        ->and($verify['needs'])->toBe('publish')
        ->and($attest['needs'])->toBe(['publish', 'verify'])
        ->and(array_column($verify['steps'], 'name'))->toContain(
            'Verify workflow-built digest and BuildKit attestations',
            'Smoke-test the published digest',
        );
});

it('never reuses a registry-sourced image for a new workflow run', function (): void {
    $publish = containerPublishingWorkflow()['jobs']['publish'];
    $scripts = containerPublishingRunScripts($publish);
    $stepIds = array_filter(array_column($publish['steps'], 'id'));

    expect($stepIds)->not->toContain('existing'.'-image', 'image', 'candidate')
        ->and($publish['outputs'])->not->toHaveKeys(['final_'.'exists', 'candidate_'.'tag'])
        ->and($scripts)->not->toContain(
            'manifests/${image_tag}',
            'EXISTING_IMAGE_DIGEST',
            'exists=true',
            'exists=false',
        )->and(containerPublishingStep($publish, 'build'))->not->toHaveKey('if');
});

it('verifies the published run tag and attestation after signing', function (): void {
    $attest = containerPublishingWorkflow()['jobs']['attest'];
    $names = array_column($attest['steps'], 'name');
    $attestationIndex = array_search('Generate GitHub artifact attestation', $names, true);
    $selectedVerificationIndex = array_search('Verify selected GitHub artifact attestation', $names, true);
    $snapshotIndex = array_search('Verify published run tag and artifact attestation', $names, true);
    $summaryIndex = array_search('Record published image identity', $names, true);
    $snapshot = containerPublishingNamedStep($attest, 'Verify published run tag and artifact attestation');

    expect($attestationIndex)->toBeInt()
        ->and($selectedVerificationIndex)->toBeInt()->toBeGreaterThan($attestationIndex)
        ->and($snapshotIndex)->toBeInt()->toBeGreaterThan($selectedVerificationIndex)
        ->and($summaryIndex)->toBeInt()->toBeGreaterThan($snapshotIndex)
        ->and($snapshot['run'])->toContain(
            'grep -Eq "^build-${GITHUB_SHA}-[0-9]+-[0-9]+$"',
            '--dump-header "$headers_file"',
            'byte_digest="sha256:$(sha256sum "$manifest_file"',
            'test "$registry_digest" = "$IMAGE_DIGEST"',
            '.mediaType == "application/vnd.oci.image.index.v1+json"',
            'gh attestation verify "oci://${DIGEST_REF}"',
            'The recorded digest remains canonical even if a registry tag later changes.',
        );
});

it('permits only the build and attestation registry writes', function (): void {
    $workflow = containerPublishingWorkflow();
    $serialized = (string) file_get_contents(containerPublishingWorkflowPath());
    $actionNames = array_count_values(containerPublishingActionNames($workflow));

    expect($actionNames['docker/build-push-action'] ?? 0)->toBe(1)
        ->and($actionNames['actions/attest'] ?? 0)->toBe(1)
        ->and(substr_count($serialized, 'push: true'))->toBe(1)
        ->and(substr_count($serialized, 'push-to-registry: true'))->toBe(1)
        ->and($serialized)->not->toMatch(containerPublishingForbiddenCommandPattern())
        ->not->toContain('package delete', 'tag delete');
});

it('derives the complete platform inventory from the exact verified index bytes', function (): void {
    $verification = containerPublishingNamedStep(
        containerPublishingWorkflow()['jobs']['verify'],
        'Verify workflow-built digest and BuildKit attestations',
    );

    expect($verification['run'])
        ->toContain('jq -e \'')
        ->toContain('\' "$manifest_file"')
        ->not->toContain('docker buildx imagetools inspect "$TAG_REF"');
});

it('preserves multi-architecture metadata SBOM provenance and exact index verification', function (): void {
    $workflow = containerPublishingWorkflow();
    $build = containerPublishingStep($workflow['jobs']['publish'], 'build');
    $verification = containerPublishingNamedStep(
        $workflow['jobs']['verify'],
        'Verify workflow-built digest and BuildKit attestations',
    );

    expect($build['with']['context'])->toBe('https://github.com/SecPal/api.git#${{ github.sha }}')
        ->and($build['with']['platforms'])->toBe('linux/amd64,linux/arm64')
        ->and($build['with']['push'])->toBeTrue()
        ->and($build['with']['pull'])->toBeTrue()
        ->and($build['with']['sbom'])->toContain('buildkit-syft-scanner:stable-1@sha256:')
        ->and($build['with']['provenance'])->toBe('mode=max')
        ->and($build['with']['labels'])->toContain(
            'org.opencontainers.image.source=https://github.com/SecPal/api',
            'org.opencontainers.image.revision=${{ github.sha }}',
            'org.opencontainers.image.title=SecPal API',
            'org.opencontainers.image.description=Production API for SecPal operations software',
            'org.opencontainers.image.licenses=AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution',
            'org.opencontainers.image.created=${{ steps.metadata.outputs.created }}',
        )->and($verification['run'])->toContain(
            'TAG_REF="${CANONICAL_IMAGE}:${PUBLISHED_TAG}"',
            'DIGEST_REF="${CANONICAL_IMAGE}@${IMAGE_DIGEST}"',
            'byte_digest="sha256:$(sha256sum "$manifest_file"',
            'test "$byte_digest" = "$IMAGE_DIGEST"',
            'test "$registry_digest" = "$IMAGE_DIGEST"',
            '== ["linux/amd64", "linux/arm64"]',
            '| length) == 2',
            '.platform.os == "unknown" and .platform.architecture == "unknown"',
            '.SPDXID == "SPDXRef-DOCUMENT"',
            '.Provenance',
            'buildkit_completeness.request == true',
            'buildkit_completeness.resolvedDependencies == true',
            '.uri == $source and .digest.sha1 == $revision',
        );
});

it('smoke-tests exactly both runtime platform digests before attestation', function (): void {
    $workflow = containerPublishingWorkflow();
    $verify = $workflow['jobs']['verify'];
    $smoke = containerPublishingNamedStep($verify, 'Smoke-test the published digest');

    expect($smoke['run'])->toContain(
        'for platform in linux/amd64 linux/arm64; do',
        '| if length == 1 then .[0] else empty end',
        'docker pull --platform "$platform" "$PLATFORM_REF"',
        'SKIP_BUILD=1 IMAGE_TAG="$PLATFORM_REF" tests/docker/smoke.sh',
    )->and($workflow['jobs']['attest']['needs'])->toBe(['publish', 'verify']);
});

it('binds artifact attestation to repository workflow branch commit subject and digest', function (): void {
    $attest = containerPublishingWorkflow()['jobs']['attest'];
    $action = containerPublishingStep($attest, 'attest');
    $scripts = containerPublishingRunScripts($attest);

    expect($action)->not->toHaveKey('if')
        ->and($action['with'])->toBe([
            'subject-name' => '${{ env.CANONICAL_IMAGE }}',
            'subject-digest' => '${{ needs.publish.outputs.image_digest }}',
            'push-to-registry' => true,
            'create-storage-record' => false,
        ])->and(substr_count($scripts, 'gh attestation verify "oci://${DIGEST_REF}"'))->toBe(2)
        ->and($scripts)->toContain(
            '--bundle-from-oci',
            '--repo SecPal/api',
            '--signer-workflow SecPal/api/.github/workflows/publish-container.yml',
            '--signer-digest "$GITHUB_SHA"',
            '--source-ref refs/heads/main',
            '--source-digest "$GITHUB_SHA"',
            '--deny-self-hosted-runners',
        );
});

it('records the verified canonical digest and warns against discovery-tag consumption', function (): void {
    $summary = containerPublishingNamedStep(
        containerPublishingWorkflow()['jobs']['attest'],
        'Record published image identity',
    );

    expect($summary['run'])->toContain(
        '## Published SecPal API image',
        '- Source commit: \`%s\`',
        '- Discovery tag: \`%s:%s\`',
        '- Canonical digest: \`%s@%s\`',
        '\`linux/amd64\`, \`linux/arm64\`',
        '- SBOM: verified',
        '- Provenance: verified',
        '- Runtime smoke: verified',
        '- GitHub Artifact Attestation: verified',
        'The discovery tag is not a deployment or trust reference.',
        '>> "$GITHUB_STEP_SUMMARY"',
    );
});

it('registers QEMU before Buildx and pins every action to a full SHA', function (): void {
    $workflow = containerPublishingWorkflow();
    $rawWorkflow = (string) file_get_contents(containerPublishingWorkflowPath());
    $actionNames = [];
    $loginSteps = [];
    $buildxSteps = [];
    $qemuSteps = [];
    $checkoutSteps = [];
    $allowedActions = [
        'actions/checkout',
        'actions/attest',
        'docker/setup-qemu-action',
        'docker/setup-buildx-action',
        'docker/login-action',
        'docker/build-push-action',
        'shivammathur/setup-php',
    ];

    foreach (['publish', 'verify'] as $jobId) {
        $uses = array_column($workflow['jobs'][$jobId]['steps'], 'uses');
        $qemu = array_search(
            'docker/setup-qemu-action@96fe6ef7f33517b61c61be40b68a1882f3264fb8',
            $uses,
            true,
        );
        $buildx = array_search(
            'docker/setup-buildx-action@bb05f3f5519dd87d3ba754cc423b652a5edd6d2c',
            $uses,
            true,
        );

        expect($qemu)->toBeInt()->toBeLessThan($buildx);
    }

    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] as $step) {
            if (! isset($step['uses'])) {
                continue;
            }

            $actionName = explode('@', $step['uses'], 2)[0];
            $actionNames[] = $actionName;
            expect($step['uses'])->toMatch('/\A[^@\s]+@[0-9a-f]{40}\z/')
                ->and($actionName)->toBeIn($allowedActions);

            match ($actionName) {
                'actions/checkout' => $checkoutSteps[] = $step,
                'docker/login-action' => $loginSteps[] = $step,
                'docker/setup-buildx-action' => $buildxSteps[] = $step,
                'docker/setup-qemu-action' => $qemuSteps[] = $step,
                default => null,
            };
        }
    }

    preg_match_all(
        '/^\s+# v?\d+\.\d+\.\d+\R\s+uses: [^@\s]+@[0-9a-f]{40}$/m',
        $rawWorkflow,
        $pinnedLines,
    );

    expect($pinnedLines[0])->toHaveCount(count($actionNames))
        ->and($checkoutSteps)->toHaveCount(4)
        ->and($loginSteps)->toHaveCount(3)
        ->and($buildxSteps)->toHaveCount(2)
        ->and($qemuSteps)->toHaveCount(2);

    foreach ($checkoutSteps as $checkoutStep) {
        expect($checkoutStep['with'])->toBe([
            'ref' => '${{ github.sha }}',
            'persist-credentials' => false,
        ]);
    }

    foreach ($loginSteps as $loginStep) {
        expect($loginStep['with'])->toBe([
            'registry' => '${{ env.GHCR_HOST }}',
            'username' => '${{ github.actor }}',
            'password' => '${{ secrets.GITHUB_TOKEN }}',
        ]);
    }

    foreach ($buildxSteps as $buildxStep) {
        expect($buildxStep['with'])->toBe([
            'version' => 'v0.36.0',
            'driver-opts' => 'image=docker.io/moby/buildkit:buildx-stable-1@sha256:2f5adac4ecd194d9f8c10b7b5d7bceb5186853db1b26e5abd3a657af0b7e26ec',
        ]);
    }

    foreach ($qemuSteps as $qemuStep) {
        expect($qemuStep['with'])->toBe([
            'image' => 'docker.io/tonistiigi/binfmt:latest@sha256:400a4873b838d1b89194d982c45e5fb3cda4593fbfd7e08a02e76b03b21166f0',
            'platforms' => 'arm64',
        ]);
    }
});

it('preserves deterministic metadata and the complete runtime smoke contract', function (): void {
    $workflow = containerPublishingWorkflow();
    $metadata = containerPublishingStep($workflow['jobs']['publish'], 'metadata');
    $smokeScript = (string) file_get_contents(dirname(__DIR__, 2).'/tests/docker/smoke.sh');

    expect($metadata['run'])->toBe(
        'printf \'created=%s\\n\' "$(git show -s --format=%cI "$GITHUB_SHA")" >> "$GITHUB_OUTPUT"',
    )->and($smokeScript)
        ->toContain(
            'if [ "${SKIP_BUILD:-0}" = 1 ]; then',
            'test "$(id -u)" -eq 10001',
            'test "$(id -g)" -eq 10001',
            'postgres_image=${POSTGRES_IMAGE:-postgres:16.10-bookworm@sha256:38471f330eb885e04de130b768d6db4e10469e2311879c7e5c699f6d2d8a1c74}',
            'valkey_image=${VALKEY_IMAGE:-valkey/valkey:9.1.1-trixie@sha256:3acc0687f2a2e1091fae6450d7842dd658c941338cf0a873ddd9e14b9e4ea4dd}',
            'assert_http /health/live 200',
            'assert_http /health/ready 200',
        )->not->toMatch('/(?:postgres|valkey)_image=\$\{[A-Z_]+:-[^}\s@]+\}/');
});

it('keeps the pull-request container workflow read-only and path-aware', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/container-image.yml');
    $paths = $workflow['on']['pull_request']['paths'];
    $scripts = containerPublishingRunScripts($workflow['jobs']['build-and-test']);

    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['jobs']['build-and-test'])->not->toHaveKey('permissions')
        ->and($paths)->toContain(
            '.github/workflows/publish-container.yml',
            'tests/Policy/**',
            'tests/docker/**',
            'Dockerfile',
            'docker/**',
            'composer.json',
            'composer.lock',
            'phpunit.xml',
        )->not->toContain('scripts/'.'promote-ghcr'.'-index.sh', 'tests/container/**', 'tests/fixtures/**')
        ->and($scripts)->toContain(
            'composer test:container-policy',
            'hadolint/hadolint:',
            'koalaman/shellcheck:',
            'tests/docker/smoke.sh',
        )->not->toContain('docker login', 'actions/attest', 'promote-ghcr'.'-index');
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
