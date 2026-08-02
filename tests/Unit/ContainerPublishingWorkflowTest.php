<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Symfony\Component\Yaml\Yaml;

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

function containerPublishingForbiddenCommandPattern(): string
{
    return '/(?:\bdocker\h+(?:[a-z-]+\h+)*prune\b|\bdocker\h+(?:push|manifest\h+push|buildx\h+(?:(?:build|bake)\b[^\r\n]*(?:--push\b|(?:--output|-o)(?:=|\h+)type=registry\b)|imagetools\h+create))\b|\b(?:oras|podman)\h+(?:push|cp|copy)\b|\bcrane\h+(?:push|copy)\b|\bskopeo\h+(?:copy|sync)\b|\bregctl\h+(?:image|manifest)\h+(?:copy|put)\b|\bcurl\b[^\r\n]*(?:(?:-X|--request)\h*(?:PUT|POST|PATCH|DELETE)\b|(?:-T|--upload-file|--form|-F)\b))/i';
}

function containerPublishingManifestAccept(): string
{
    return implode(', ', [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.docker.distribution.manifest.v2+json',
        'application/vnd.docker.distribution.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v1+prettyjws',
    ]);
}

function secPalApiImageReferenceIsCanonical(string $reference, bool $digestOnly = false): bool
{
    if ($reference === '' || preg_match('/[\x00-\x20\x7f]/', $reference) === 1) {
        return false;
    }

    if (preg_match('/\Aghcr\.io\/secpal\/api@sha256:[0-9a-f]{64}\z/', $reference) === 1) {
        return true;
    }

    return ! $digestOnly
        && preg_match('/\Aghcr\.io\/secpal\/api:sha-[0-9a-f]{40}\z/', $reference) === 1;
}

it('defines a main-only publish workflow with isolated least-privilege jobs', function (): void {
    expect(is_file(containerPublishingWorkflowPath()))->toBeTrue('Missing immutable container publishing workflow.');

    $workflow = containerPublishingWorkflow();

    expect($workflow['name'])->toBe('Publish Container')
        ->and($workflow['on'])->toBe(['push' => ['branches' => ['main']]])
        ->and($workflow['permissions'])->toBe([])
        ->and($workflow['env'])->toBe([
            'GHCR_HOST' => 'ghcr.io',
            'GHCR_REPOSITORY_PATH' => 'secpal/api',
            'CANONICAL_IMAGE' => 'ghcr.io/secpal/api',
        ])
        ->and(array_keys($workflow['jobs']))->toBe(['validate', 'publish', 'verify']);

    $validate = $workflow['jobs']['validate'];
    $publish = $workflow['jobs']['publish'];
    $verify = $workflow['jobs']['verify'];

    expect($validate['permissions'])->toBe(['contents' => 'read'])
        ->and($validate['runs-on'])->toBe('ubuntu-latest')
        ->and($validate['concurrency'])->toBe([
            'group' => 'publish-container-validate-${{ github.repository }}-${{ github.sha }}',
            'cancel-in-progress' => false,
        ])
        ->and($publish['name'])->toBe('Publish API Image')
        ->and($publish['needs'])->toBe('validate')
        ->and($publish['permissions'])->toBe([
            'contents' => 'read',
            'packages' => 'write',
            'attestations' => 'write',
            'id-token' => 'write',
        ])
        ->and($publish['runs-on'])->toBe('ubuntu-latest')
        ->and($publish['concurrency'])->toBe([
            'group' => 'publish-container-${{ github.repository }}-${{ github.sha }}',
            'cancel-in-progress' => false,
        ])
        ->and($verify['needs'])->toBe('publish')
        ->and($verify['permissions'])->toBe([
            'contents' => 'read',
            'packages' => 'read',
        ])
        ->and($verify['runs-on'])->toBe('ubuntu-latest');

    foreach ([$validate, $publish, $verify] as $job) {
        expect($job['timeout-minutes'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('validates before publishing without registry credentials or write operations', function (): void {
    $validate = containerPublishingWorkflow()['jobs']['validate'];
    $scripts = containerPublishingRunScripts($validate);
    $uses = array_column($validate['steps'], 'uses');

    expect($scripts)
        ->toContain('tests/docker/smoke.sh')
        ->toContain('hadolint/hadolint:v2.14.0-debian')
        ->toContain('koalaman/shellcheck:v0.10.0@sha256:')
        ->toContain('docker/healthchecks/http-live.sh tests/docker/smoke.sh')
        ->toContain('php artisan test tests/Unit/ContainerImageDefinitionTest.php tests/Unit/ContainerPublishingWorkflowTest.php')
        ->not->toContain('docker login')
        ->not->toContain('docker push')
        ->and($uses)->not->toContain(
            'docker/login-action',
            'docker/build-push-action',
            'actions/attest',
        );
});

it('pins every container validation tool to an immutable version or image digest', function (): void {
    $hadolint = 'hadolint/hadolint:v2.14.0-debian@sha256:158cd0184dcaa18bd8ec20b61f4c1cabdf8b32a592d062f57bdcb8e4c1d312e2';
    $shellcheck = 'koalaman/shellcheck:v0.10.0@sha256:2097951f02e735b613f4a34de20c40f937a6c8f18ecb170612c88c34517221fb';
    $publishingWorkflow = containerPublishingWorkflow();
    $publishingValidate = $publishingWorkflow['jobs']['validate'];
    $pullRequestWorkflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/container-image.yml');
    $pullRequestValidate = $pullRequestWorkflow['jobs']['build-and-test'];
    $setupPhp = containerPublishingNamedStep($publishingValidate, 'Set up PHP');

    expect($setupPhp['with']['tools'])->toBe('composer:2.10.2');

    foreach ([$publishingValidate, $pullRequestValidate] as $job) {
        $scripts = containerPublishingRunScripts($job);

        expect($scripts)
            ->toContain("docker run --rm -i {$hadolint} < Dockerfile")
            ->toContain("docker run --rm -v \"\$PWD:/mnt:ro\" -w /mnt {$shellcheck}")
            ->toContain('docker/healthchecks/http-live.sh tests/docker/smoke.sh')
            ->not->toContain('apt-get', 'composer:v2')
            ->not->toMatch('/hadolint\/hadolint:[^\s@]+(?:\s|$)/')
            ->not->toMatch('/koalaman\/shellcheck:[^\s@]+(?:\s|$)/');
    }
});

it('publishes one full-SHA multi-architecture tag with exact OCI metadata and attestations', function (): void {
    $workflow = containerPublishingWorkflow();
    $publish = $workflow['jobs']['publish'];
    $existingImage = containerPublishingStep($publish, 'existing-image');
    $build = containerPublishingStep($publish, 'build');
    $image = containerPublishingStep($publish, 'image');
    $attest = containerPublishingStep($publish, 'attest');
    $metadata = containerPublishingStep($publish, 'metadata');

    expect($existingImage['env'])->toBe([
        'GHCR_TOKEN' => '${{ secrets.GITHUB_TOKEN }}',
        'GH_TOKEN' => '${{ github.token }}',
        'EXPECTED_CREATED' => '${{ steps.metadata.outputs.created }}',
        'MANIFEST_ACCEPT' => containerPublishingManifestAccept(),
    ])->and($existingImage['run'])
        ->toContain('scope=repository:${GHCR_REPOSITORY_PATH}:pull')
        ->toContain('https://${GHCR_HOST}/v2/${GHCR_REPOSITORY_PATH}/manifests/${image_tag}')
        ->toContain('digest_ref="${CANONICAL_IMAGE}@${digest}"')
        ->toContain('case "$status" in')
        ->toContain('200)')
        ->toContain('404)')
        ->toContain('exit 1')
        ->toContain('org.opencontainers.image.source')
        ->toContain('org.opencontainers.image.revision')
        ->toContain('org.opencontainers.image.title')
        ->toContain('org.opencontainers.image.description')
        ->toContain('org.opencontainers.image.licenses')
        ->toContain('org.opencontainers.image.created')
        ->toContain('gh attestation verify "oci://$digest_ref"')
        ->toContain('--bundle-from-oci')
        ->and($metadata['run'])->toContain('git show -s --format=%cI "$GITHUB_SHA"')
        ->and($build['if'])->toBe("steps.existing-image.outputs.exists == 'false'")
        ->and($build['with']['context'])->toBe('https://github.com/SecPal/api.git#${{ github.sha }}')
        ->and($build['with']['push'])->toBeTrue()
        ->and($build['with']['platforms'])->toBe('linux/amd64,linux/arm64')
        ->and($build['with']['tags'])->toBe('${{ env.CANONICAL_IMAGE }}:sha-${{ github.sha }}')
        ->and($build['with']['sbom'])->toBe(
            'generator=docker.io/docker/buildkit-syft-scanner:stable-1@sha256:79e7b013cbec16bbb436f312819a49a4a57752b2270c1a9332ae1a10fcc82a68',
        )
        ->and($build['with']['provenance'])->toBe('mode=max')
        ->and($build['with']['labels'])->toContain(
            'org.opencontainers.image.source=https://github.com/SecPal/api',
            'org.opencontainers.image.revision=${{ github.sha }}',
            'org.opencontainers.image.title=SecPal API',
            'org.opencontainers.image.description=Production API for SecPal operations software',
            'org.opencontainers.image.licenses=AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution',
            'org.opencontainers.image.created=${{ steps.metadata.outputs.created }}',
        )
        ->and($image['env'])->toBe([
            'EXISTING_IMAGE_DIGEST' => '${{ steps.existing-image.outputs.digest }}',
            'BUILT_IMAGE_DIGEST' => '${{ steps.build.outputs.digest }}',
        ])->and($image['run'])
        ->toContain('digest=$EXISTING_IMAGE_DIGEST')
        ->toContain('digest=$BUILT_IMAGE_DIGEST')
        ->toContain("grep -Eq '^sha256:[0-9a-f]{64}$'")
        ->and($attest['if'])->toBe("steps.existing-image.outputs.exists == 'false'")
        ->and($attest['with'])->toBe([
            'subject-name' => '${{ env.CANONICAL_IMAGE }}',
            'subject-digest' => '${{ steps.build.outputs.digest }}',
            'push-to-registry' => true,
            'create-storage-record' => false,
        ])
        ->and($publish['outputs'])->toBe([
            'image_digest' => '${{ steps.image.outputs.digest }}',
            'image_created' => '${{ steps.metadata.outputs.created }}',
        ]);

    $checkout = containerPublishingStep($publish, 'checkout');
    $login = containerPublishingStep($publish, 'registry-login');

    expect($checkout['with'])->toBe([
        'ref' => '${{ github.sha }}',
        'persist-credentials' => false,
    ])->and($login['with'])->toBe([
        'registry' => '${{ env.GHCR_HOST }}',
        'username' => '${{ github.actor }}',
        'password' => '${{ secrets.GITHUB_TOKEN }}',
    ]);
});

it('reuses only an already-attested image without rebuilding or moving the SHA tag', function (): void {
    $workflow = containerPublishingWorkflow();
    $publish = $workflow['jobs']['publish'];
    $existingImage = containerPublishingStep($publish, 'existing-image');
    $build = containerPublishingStep($publish, 'build');
    $image = containerPublishingStep($publish, 'image');
    $attest = containerPublishingStep($publish, 'attest');
    $publishScripts = containerPublishingRunScripts($publish);
    $uses = array_column($publish['steps'], 'uses');

    expect($existingImage['env'])->toBe([
        'GHCR_TOKEN' => '${{ secrets.GITHUB_TOKEN }}',
        'GH_TOKEN' => '${{ github.token }}',
        'EXPECTED_CREATED' => '${{ steps.metadata.outputs.created }}',
        'MANIFEST_ACCEPT' => containerPublishingManifestAccept(),
    ])->and($existingImage['run'])
        ->toContain('set -euo pipefail')
        ->toContain('.mediaType == "application/vnd.oci.image.index.v1+json"')
        ->toContain('digest="sha256:$(sha256sum "$manifest_file"')
        ->toContain('digest_ref="${CANONICAL_IMAGE}@${digest}"')
        ->toContain('== ["linux/amd64", "linux/arm64"]')
        ->toContain('org.opencontainers.image.source')
        ->toContain('org.opencontainers.image.revision')
        ->toContain('org.opencontainers.image.title')
        ->toContain('org.opencontainers.image.description')
        ->toContain('org.opencontainers.image.licenses')
        ->toContain('org.opencontainers.image.created')
        ->toContain('200)', '404)', 'exit 1')
        ->toContain('gh attestation verify "oci://$digest_ref"')
        ->toContain('--bundle-from-oci')
        ->toContain('--repo SecPal/api')
        ->toContain('--signer-workflow SecPal/api/.github/workflows/publish-container.yml')
        ->toContain('--signer-digest "$GITHUB_SHA"')
        ->toContain('--source-ref refs/heads/main')
        ->toContain('--source-digest "$GITHUB_SHA"')
        ->toContain('--deny-self-hosted-runners')
        ->and($build['if'])->toBe("steps.existing-image.outputs.exists == 'false'")
        ->and($build['with']['push'])->toBeTrue()
        ->and($build['with']['tags'])->toBe('${{ env.CANONICAL_IMAGE }}:sha-${{ github.sha }}')
        ->and($image['env'])->toBe([
            'EXISTING_IMAGE_DIGEST' => '${{ steps.existing-image.outputs.digest }}',
            'BUILT_IMAGE_DIGEST' => '${{ steps.build.outputs.digest }}',
        ])->and($image['run'])
        ->toContain('if [ "${{ steps.existing-image.outputs.exists }}" = true ]; then')
        ->toContain('digest=$EXISTING_IMAGE_DIGEST')
        ->toContain('digest=$BUILT_IMAGE_DIGEST')
        ->toContain("grep -Eq '^sha256:[0-9a-f]{64}$'")
        ->and($attest['if'])->toBe("steps.existing-image.outputs.exists == 'false'")
        ->and($attest['with'])->toBe([
            'subject-name' => '${{ env.CANONICAL_IMAGE }}',
            'subject-digest' => '${{ steps.build.outputs.digest }}',
            'push-to-registry' => true,
            'create-storage-record' => false,
        ])
        ->and($workflow['jobs']['verify']['needs'])->toBe('publish')
        ->and(array_count_values($uses)['docker/build-push-action@53b7df96c91f9c12dcc8a07bcb9ccacbed38856a'] ?? 0)->toBe(1)
        ->and(array_count_values($uses)['actions/attest@508db95dd578ae2727ebd6217d5ba78e4fbda05d'] ?? 0)->toBe(1)
        ->and($publishScripts)->not->toMatch(containerPublishingForbiddenCommandPattern());
});

it('distinguishes an absent SHA tag from every relevant manifest representation', function (): void {
    $publish = containerPublishingWorkflow()['jobs']['publish'];
    $existingImage = containerPublishingStep($publish, 'existing-image');

    expect($existingImage['env']['MANIFEST_ACCEPT'])->toBe(containerPublishingManifestAccept())
        ->and($existingImage['run'])
        ->toContain('--header "Accept: ${MANIFEST_ACCEPT}"')
        ->not->toContain("--header 'Accept: application/vnd.oci.image.index.v1+json'")
        ->toContain('200)')
        ->toContain('jq -e \'.mediaType == "application/vnd.oci.image.index.v1+json"\' "$manifest_file"')
        ->toContain('404)')
        ->toContain('printf \'exists=false\\ndigest=\\n\' >> "$GITHUB_OUTPUT"');
});

it('verifies the remote digest, runtime platforms, labels, BuildKit attestations, GitHub attestation, and runtime contract', function (): void {
    $verify = containerPublishingWorkflow()['jobs']['verify'];
    $scripts = containerPublishingRunScripts($verify);
    $smokeScript = file_get_contents(dirname(__DIR__, 2).'/tests/docker/smoke.sh');

    expect($verify['env'])->toBe([
        'IMAGE_DIGEST' => '${{ needs.publish.outputs.image_digest }}',
        'IMAGE_CREATED' => '${{ needs.publish.outputs.image_created }}',
        'IMAGE_TAG' => 'sha-${{ github.sha }}',
    ])->and($scripts)
        ->toContain('DIGEST_REF="${CANONICAL_IMAGE}@${IMAGE_DIGEST}"')
        ->toContain('docker buildx imagetools inspect "$CANONICAL_IMAGE:$IMAGE_TAG" --raw')
        ->toContain("sha256sum | awk '{print $1}'")
        ->toContain('test "$tag_digest" = "$IMAGE_DIGEST"')
        ->toContain('vnd.docker.reference.type')
        ->toContain('linux/amd64', 'linux/arm64')
        ->toContain('(index .Image \"${platform}\")')
        ->toContain('org.opencontainers.image.source')
        ->toContain('org.opencontainers.image.revision')
        ->toContain('org.opencontainers.image.title')
        ->toContain('org.opencontainers.image.description')
        ->toContain('org.opencontainers.image.licenses')
        ->toContain('org.opencontainers.image.created')
        ->toContain('(index .SBOM \"${platform}\").SPDX')
        ->toContain('(index .Provenance \"${platform}\").SLSA')
        ->toContain('https://github.com/SecPal/api.git#${GITHUB_SHA}')
        ->toContain('.buildDefinition.buildType')
        ->not->toContain('.runDetails.metadata.buildkit_hermetic == true')
        ->toContain('.runDetails.metadata.buildkit_completeness.request == true')
        ->toContain('.runDetails.metadata.buildkit_completeness.resolvedDependencies == true')
        ->toContain('any(.buildDefinition.resolvedDependencies[];')
        ->toContain('.digest.sha1 == $revision')
        ->not->toContain('.metadata.completeness', 'any(.materials[];')
        ->toContain('gh attestation verify "oci://$DIGEST_REF"')
        ->toContain('--bundle-from-oci')
        ->toContain('--repo SecPal/api')
        ->toContain('--signer-workflow SecPal/api/.github/workflows/publish-container.yml')
        ->toContain('--signer-digest "$GITHUB_SHA"')
        ->toContain('--source-digest "$GITHUB_SHA"')
        ->toContain('--deny-self-hosted-runners')
        ->toContain('docker pull --platform "$platform" "$PLATFORM_REF"')
        ->toContain('SKIP_BUILD=1 IMAGE_TAG="$PLATFORM_REF" tests/docker/smoke.sh')
        ->not->toContain('docker pull "$CANONICAL_IMAGE:$IMAGE_TAG"')
        ->and($smokeScript)
        ->toContain('if [ "${SKIP_BUILD:-0}" = 1 ]; then')
        ->toContain('test "$(id -u)" -eq 10001')
        ->toContain('test "$(id -g)" -eq 10001');
});

it('smoke-tests both runtime platforms from the published index digest', function (): void {
    $verify = containerPublishingWorkflow()['jobs']['verify'];
    $qemu = containerPublishingNamedStep($verify, 'Set up QEMU for runtime verification');
    $smoke = containerPublishingNamedStep($verify, 'Smoke-test the published digest');

    expect($qemu['uses'])->toBe('docker/setup-qemu-action@96fe6ef7f33517b61c61be40b68a1882f3264fb8')
        ->and($qemu['with'])->toBe([
            'image' => 'docker.io/tonistiigi/binfmt:latest@sha256:400a4873b838d1b89194d982c45e5fb3cda4593fbfd7e08a02e76b03b21166f0',
            'platforms' => 'arm64',
        ])
        ->and($smoke['run'])
        ->toContain('DIGEST_REF="${CANONICAL_IMAGE}@${IMAGE_DIGEST}"')
        ->toContain('for platform in linux/amd64 linux/arm64; do')
        ->toContain('select(.platform.os == $os and .platform.architecture == $architecture)')
        ->toContain('PLATFORM_REF="${CANONICAL_IMAGE}@${platform_digest}"')
        ->toContain('docker pull --platform "$platform" "$PLATFORM_REF"')
        ->toContain('SKIP_BUILD=1 IMAGE_TAG="$PLATFORM_REF" tests/docker/smoke.sh')
        ->not->toContain('SKIP_BUILD=1 IMAGE_TAG="$DIGEST_REF" tests/docker/smoke.sh');
});

it('pins every action to a full commit SHA with an adjacent version comment', function (): void {
    $workflow = containerPublishingWorkflow();
    $rawWorkflow = file_get_contents(containerPublishingWorkflowPath());
    $actionCount = 0;
    $actionNames = [];
    $loginSteps = [];
    $setupBuildxSteps = [];
    $setupQemuSteps = [];
    $allowedActions = [
        'actions/checkout',
        'actions/attest',
        'docker/setup-qemu-action',
        'docker/setup-buildx-action',
        'docker/login-action',
        'docker/build-push-action',
        'shivammathur/setup-php',
    ];

    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] as $step) {
            if (! isset($step['uses'])) {
                continue;
            }

            $actionCount++;
            $actionName = explode('@', $step['uses'], 2)[0];
            $actionNames[] = $actionName;
            if ($actionName === 'docker/login-action') {
                $loginSteps[] = $step;
            }
            if ($actionName === 'docker/setup-buildx-action') {
                $setupBuildxSteps[] = $step;
            }
            if ($actionName === 'docker/setup-qemu-action') {
                $setupQemuSteps[] = $step;
            }
            expect($step['uses'])->toMatch('/^[^@\s]+@[0-9a-f]{40}$/')
                ->and($actionName)->toBeIn($allowedActions);
        }
    }

    preg_match_all('/^\s+# v?\d+\.\d+\.\d+\R\s+uses: [^@\s]+@[0-9a-f]{40}$/m', $rawWorkflow, $pinnedLines);
    expect(count($pinnedLines[0]))->toBe($actionCount)
        ->and(array_count_values($actionNames)['docker/build-push-action'] ?? 0)->toBe(1)
        ->and(array_count_values($actionNames)['actions/attest'] ?? 0)->toBe(1)
        ->and(count($loginSteps))->toBe(2);

    foreach ($loginSteps as $loginStep) {
        expect($loginStep['with'])->toBe([
            'registry' => '${{ env.GHCR_HOST }}',
            'username' => '${{ github.actor }}',
            'password' => '${{ secrets.GITHUB_TOKEN }}',
        ]);
    }

    foreach ($setupBuildxSteps as $setupBuildxStep) {
        expect($setupBuildxStep['with'])->toBe([
            'version' => 'v0.36.0',
            'driver-opts' => 'image=docker.io/moby/buildkit:buildx-stable-1@sha256:2f5adac4ecd194d9f8c10b7b5d7bceb5186853db1b26e5abd3a657af0b7e26ec',
        ]);
    }

    expect($setupQemuSteps)->toHaveCount(2);

    foreach ($setupQemuSteps as $setupQemuStep) {
        expect($setupQemuStep['with'])->toBe([
            'image' => 'docker.io/tonistiigi/binfmt:latest@sha256:400a4873b838d1b89194d982c45e5fb3cda4593fbfd7e08a02e76b03b21166f0',
            'platforms' => 'arm64',
        ]);
    }
});

it('rejects moving tags, broad credentials, destructive registry operations, and deployment', function (): void {
    $workflow = containerPublishingWorkflow();
    $rawWorkflow = file_get_contents(containerPublishingWorkflowPath());
    $publishedTags = preg_split(
        '/\R/',
        containerPublishingStep($workflow['jobs']['publish'], 'build')['with']['tags'],
        flags: PREG_SPLIT_NO_EMPTY,
    );

    expect($publishedTags)->toBe(['${{ env.CANONICAL_IMAGE }}:sha-${{ github.sha }}'])
        ->and($rawWorkflow)
        ->not->toMatch('/sha-\$\{\{\s*github\.sha\s*\}\}.*(?:cut|substr|0:7|0,\s*7)/i')
        ->not->toMatch('/\$\{\{\s*secrets\.(?!GITHUB_TOKEN\b)/')
        ->not->toContain('docker.io/secpal', 'quay.io', 'gcr.io', 'delete:packages', 'packages: delete')
        ->not->toMatch('/docker\.io\/(?:tonistiigi\/binfmt|moby\/buildkit):[^@\s]+(?:\s|$)/')
        ->not->toMatch(containerPublishingForbiddenCommandPattern())
        ->not->toMatch('/\bdeploy(?:ment|ments)?\b/i')
        ->not->toContain('workflow_dispatch', 'pull_request', 'pull_request_target', 'environment:');
});

it('binds every publisher reference to the non-configurable GHCR identity', function (): void {
    $workflow = containerPublishingWorkflow();
    $publish = $workflow['jobs']['publish'];
    $verifyScripts = containerPublishingRunScripts($workflow['jobs']['verify']);
    $existingImageScript = containerPublishingStep($publish, 'existing-image')['run'];
    $attest = containerPublishingStep($publish, 'attest');

    expect($workflow['env'])->toBe([
        'GHCR_HOST' => 'ghcr.io',
        'GHCR_REPOSITORY_PATH' => 'secpal/api',
        'CANONICAL_IMAGE' => 'ghcr.io/secpal/api',
    ])->and(implode("\n", $workflow['env']))
        ->not->toContain('${{', 'vars.', 'inputs.', 'secrets.')
        ->and($existingImageScript)
        ->toContain('scope=repository:${GHCR_REPOSITORY_PATH}:pull')
        ->toContain('service=${GHCR_HOST}')
        ->toContain('https://${GHCR_HOST}/token')
        ->toContain('https://${GHCR_HOST}/v2/${GHCR_REPOSITORY_PATH}/manifests/${image_tag}')
        ->not->toMatch('/docker[^\r\n]*GHCR_REPOSITORY_PATH/')
        ->and($attest['with']['subject-name'])->toBe('${{ env.CANONICAL_IMAGE }}')
        ->and($verifyScripts)
        ->toContain('DIGEST_REF="${CANONICAL_IMAGE}@${IMAGE_DIGEST}"')
        ->toContain('PLATFORM_REF="${CANONICAL_IMAGE}@${platform_digest}"')
        ->toContain('docker pull --platform "$platform" "$PLATFORM_REF"')
        ->toContain('SKIP_BUILD=1 IMAGE_TAG="$PLATFORM_REF" tests/docker/smoke.sh')
        ->not->toMatch('/docker[^\r\n]*GHCR_REPOSITORY_PATH/');
});

it('accepts only canonical resolved SecPal API artifact references', function (string $reference): void {
    expect(secPalApiImageReferenceIsCanonical($reference))->toBeTrue();
})->with([
    'ghcr.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567',
    'ghcr.io/secpal/api@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
]);

it('accepts only the canonical digest for deployment consumption', function (): void {
    expect(secPalApiImageReferenceIsCanonical(
        'ghcr.io/secpal/api@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        digestOnly: true,
    ))->toBeTrue()
        ->and(secPalApiImageReferenceIsCanonical(
            'ghcr.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567',
            digestOnly: true,
        ))->toBeFalse();
});

it('rejects ambiguous or non-canonical resolved SecPal API references', function (string $reference): void {
    expect(secPalApiImageReferenceIsCanonical($reference))->toBeFalse();
})->with([
    'secpal'.'/api:sha-0123456789abcdef0123456789abcdef01234567',
    'docker'.'.io/'.'secpal'.'/api:sha-0123456789abcdef0123456789abcdef01234567',
    'index'.'.docker'.'.io/'.'secpal'.'/api@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    'registry-1'.'.docker'.'.io/'.'secpal'.'/api:latest',
    'GHCR.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567',
    'ghcr.io/SecPal/api:sha-0123456789abcdef0123456789abcdef01234567',
    'ghcr.io/other/api:sha-0123456789abcdef0123456789abcdef01234567',
    'ghcr.io/secpal/frontend:sha-0123456789abcdef0123456789abcdef01234567',
    'ghcr.io/secpal/api:sha-0123456',
    'ghcr.io/secpal/api:latest',
    'ghcr.io/secpal/api:main',
    'ghcr.io/secpal/api:',
    'ghcr.io/secpal/api',
    'ghcr.io/secpal/api@sha256:0123456',
    'ghcr.io/secpal/api@sha256:0123456789ABCDEF0123456789abcdef0123456789abcdef0123456789abcdef',
    'ghcr.io/secpal/api@sha256:g123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    'ghcr.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    ' ghcr.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567',
    "ghcr.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567\n",
    'https://ghcr.io/secpal/api:sha-0123456789abcdef0123456789abcdef01234567',
    '${CANONICAL_IMAGE}@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    '$(printf ghcr.io/secpal/api):sha-0123456789abcdef0123456789abcdef01234567',
    'secpal-api:test',
    'secpal-api:phase-c-pr',
]);

it('keeps non-namespace local image tags in the smoke contract', function (): void {
    $smokeScript = file_get_contents(dirname(__DIR__, 2).'/tests/docker/smoke.sh');

    expect($smokeScript)->toContain('image=${IMAGE_TAG:-secpal-api:test}')
        ->and(secPalApiImageReferenceIsCanonical('secpal-api:test'))->toBeFalse()
        ->and(secPalApiImageReferenceIsCanonical('secpal-api:phase-c-pr'))->toBeFalse();
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
    'oras copy ghcr.io/secpal/api:test registry.example/secpal/api:test',
    'crane push image.tar registry.example/secpal/api:test',
    'skopeo sync --src docker --dest docker source destination',
    'regctl manifest put registry.example/secpal/api:test',
    'curl --request PUT --upload-file manifest.json https://registry.example/v2/api/manifests/test',
    'docker container prune --force',
    'docker image prune --force',
    'docker network prune --force',
    'docker system prune --force',
    'docker volume prune --force',
    'docker builder prune --force',
    'docker buildx prune --force',
]);

it('keeps the pull-request container workflow read-only', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/container-image.yml');
    $checkout = $workflow['jobs']['build-and-test']['steps'][0];

    expect($workflow['name'])->toBe('Container Image')
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['on']['pull_request']['paths'])->toContain('.github/workflows/publish-container.yml')
        ->and($checkout['uses'])->toBe('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1');
});
