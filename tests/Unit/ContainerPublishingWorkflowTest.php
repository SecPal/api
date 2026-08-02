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

/** @param array<string, mixed> $job */
function containerPublishingRunScripts(array $job): string
{
    return implode("\n", array_map(
        static fn (array $step): string => (string) ($step['run'] ?? ''),
        $job['steps'],
    ));
}

it('defines a main-only publish workflow with isolated least-privilege jobs', function (): void {
    expect(is_file(containerPublishingWorkflowPath()))->toBeTrue('Missing immutable container publishing workflow.');

    $workflow = containerPublishingWorkflow();

    expect($workflow['name'])->toBe('Publish Container')
        ->and($workflow['on'])->toBe(['push' => ['branches' => ['main']]])
        ->and($workflow['permissions'])->toBe([])
        ->and($workflow['env'])->toBe([
            'REGISTRY' => 'ghcr.io',
            'IMAGE_NAME' => 'secpal/api',
            'IMAGE_FQDN' => 'ghcr.io/secpal/api',
        ])
        ->and(array_keys($workflow['jobs']))->toBe(['validate', 'publish', 'verify']);

    $validate = $workflow['jobs']['validate'];
    $publish = $workflow['jobs']['publish'];
    $verify = $workflow['jobs']['verify'];

    expect($validate['permissions'])->toBe(['contents' => 'read'])
        ->and($validate['runs-on'])->toBe('ubuntu-latest')
        ->and($validate['concurrency'])->toBe([
            'group' => 'publish-container-validate-${{ github.repository }}',
            'cancel-in-progress' => true,
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
            'group' => 'publish-container-${{ github.repository }}',
            'cancel-in-progress' => false,
        ])
        ->and($verify['needs'])->toBe('publish')
        ->and($verify['permissions'])->toBe([
            'contents' => 'read',
            'packages' => 'read',
            'attestations' => 'read',
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
        ->toContain('shellcheck docker/healthchecks/http-live.sh tests/docker/smoke.sh')
        ->toContain('php artisan test tests/Unit/ContainerImageDefinitionTest.php tests/Unit/ContainerPublishingWorkflowTest.php')
        ->not->toContain('docker login')
        ->not->toContain('docker push')
        ->and($uses)->not->toContain(
            'docker/login-action',
            'docker/build-push-action',
            'actions/attest',
        );
});

it('publishes one full-SHA multi-architecture tag with exact OCI metadata and attestations', function (): void {
    $workflow = containerPublishingWorkflow();
    $publish = $workflow['jobs']['publish'];
    $build = containerPublishingStep($publish, 'build');
    $attest = containerPublishingStep($publish, 'attest');

    expect($build['with']['context'])->toBe('.')
        ->and($build['with']['push'])->toBeTrue()
        ->and($build['with']['platforms'])->toBe('linux/amd64,linux/arm64')
        ->and($build['with']['tags'])->toBe('${{ env.IMAGE_FQDN }}:sha-${{ github.sha }}')
        ->and($build['with']['sbom'])->toBeTrue()
        ->and($build['with']['provenance'])->toBe('mode=max')
        ->and($build['with']['labels'])->toContain(
            'org.opencontainers.image.source=https://github.com/SecPal/api',
            'org.opencontainers.image.revision=${{ github.sha }}',
            'org.opencontainers.image.title=SecPal API',
            'org.opencontainers.image.description=Production API for SecPal operations software',
            'org.opencontainers.image.licenses=AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution',
            'org.opencontainers.image.created=${{ steps.metadata.outputs.created }}',
        )
        ->and($attest['with'])->toBe([
            'subject-name' => 'ghcr.io/secpal/api',
            'subject-digest' => '${{ steps.build.outputs.digest }}',
            'push-to-registry' => true,
        ])
        ->and($publish['outputs']['image_digest'])->toBe('${{ steps.build.outputs.digest }}');

    $checkout = containerPublishingStep($publish, 'checkout');
    $login = containerPublishingStep($publish, 'registry-login');

    expect($checkout['with'])->toBe([
        'ref' => '${{ github.sha }}',
        'persist-credentials' => false,
    ])->and($login['with'])->toBe([
        'registry' => 'ghcr.io',
        'username' => '${{ github.actor }}',
        'password' => '${{ secrets.GITHUB_TOKEN }}',
    ]);
});

it('verifies the remote digest, runtime platforms, labels, BuildKit attestations, GitHub attestation, and runtime contract', function (): void {
    $verify = containerPublishingWorkflow()['jobs']['verify'];
    $scripts = containerPublishingRunScripts($verify);
    $smokeScript = file_get_contents(dirname(__DIR__, 2).'/tests/docker/smoke.sh');

    expect($verify['env'])->toBe([
        'IMAGE_DIGEST' => '${{ needs.publish.outputs.image_digest }}',
        'IMAGE_TAG' => 'sha-${{ github.sha }}',
    ])->and($scripts)
        ->toContain('docker buildx imagetools inspect "$IMAGE_FQDN:$IMAGE_TAG" --raw')
        ->toContain("sha256sum | awk '{print $1}'")
        ->toContain('test "$tag_digest" = "$IMAGE_DIGEST"')
        ->toContain('vnd.docker.reference.type')
        ->toContain('linux/amd64', 'linux/arm64')
        ->toContain('(index .Image \"${platform}\")')
        ->toContain('org.opencontainers.image.source')
        ->toContain('org.opencontainers.image.revision')
        ->toContain('(index .SBOM \"${platform}\").SPDX')
        ->toContain('(index .Provenance \"${platform}\").SLSA')
        ->toContain('gh attestation verify "oci://$DIGEST_REF"')
        ->toContain('--repo SecPal/api')
        ->toContain('--signer-workflow SecPal/api/.github/workflows/publish-container.yml')
        ->toContain('--source-digest "$GITHUB_SHA"')
        ->toContain('docker pull "$DIGEST_REF"')
        ->toContain('SKIP_BUILD=1 IMAGE_TAG="$DIGEST_REF" tests/docker/smoke.sh')
        ->not->toContain('docker pull "$IMAGE_FQDN:$IMAGE_TAG"')
        ->and($smokeScript)->toContain('if [ "${SKIP_BUILD:-0}" = 1 ]; then');
});

it('pins every action to a full commit SHA with an adjacent version comment', function (): void {
    $workflow = containerPublishingWorkflow();
    $rawWorkflow = file_get_contents(containerPublishingWorkflowPath());
    $actionCount = 0;
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
            expect($step['uses'])->toMatch('/^[^@\s]+@[0-9a-f]{40}$/')
                ->and(explode('@', $step['uses'], 2)[0])->toBeIn($allowedActions);
        }
    }

    preg_match_all('/^\s+# v?\d+\.\d+\.\d+\R\s+uses: [^@\s]+@[0-9a-f]{40}$/m', $rawWorkflow, $pinnedLines);
    expect(count($pinnedLines[0]))->toBe($actionCount);
});

it('rejects moving tags, broad credentials, destructive registry operations, and deployment', function (): void {
    $workflow = containerPublishingWorkflow();
    $rawWorkflow = file_get_contents(containerPublishingWorkflowPath());
    $publishedTags = preg_split(
        '/\R/',
        containerPublishingStep($workflow['jobs']['publish'], 'build')['with']['tags'],
        flags: PREG_SPLIT_NO_EMPTY,
    );

    expect($publishedTags)->toBe(['${{ env.IMAGE_FQDN }}:sha-${{ github.sha }}'])
        ->and($rawWorkflow)
        ->not->toMatch('/sha-\$\{\{\s*github\.sha\s*\}\}.*(?:cut|substr|0:7|0,\s*7)/i')
        ->not->toMatch('/\$\{\{\s*secrets\.(?!GITHUB_TOKEN\b)/')
        ->not->toContain('docker.io', 'quay.io', 'gcr.io', 'delete:packages', 'packages: delete')
        ->not->toMatch('/docker\s+(?:system|image|builder)\s+prune/i')
        ->not->toMatch('/\bdeploy(?:ment|ments)?\b/i')
        ->not->toContain('workflow_dispatch', 'pull_request', 'pull_request_target', 'environment:');
});

it('keeps the pull-request container workflow read-only', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/container-image.yml');
    $checkout = $workflow['jobs']['build-and-test']['steps'][0];

    expect($workflow['name'])->toBe('Container Image')
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['on']['pull_request']['paths'])->toContain('.github/workflows/publish-container.yml')
        ->and($checkout['uses'])->toBe('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1');
});
