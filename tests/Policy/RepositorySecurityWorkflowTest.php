<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function repositorySecurityWorkflow(string $name): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/'.$name.'.yml');

    return Yaml::parse(preg_replace('/^---\h*$/m', '', $source, 1) ?? $source);
}

it('requires repository security acceptance before publication and provides PR feedback', function (): void {
    $publisher = repositorySecurityWorkflow('publish-container')['jobs']['publish'];

    expect($publisher['needs'])->toBe(['validate', 'repository-security'])
        ->and($publisher)->not->toHaveKeys(['if', 'continue-on-error']);

    foreach (['publish-container', 'quality'] as $name) {
        $workflow = repositorySecurityWorkflow($name);
        $caller = $workflow['jobs']['repository-security'];

        expect($caller)->toBe([
            'name' => 'Repository Security',
            'permissions' => ['contents' => 'read'],
            'uses' => './.github/workflows/reusable-repository-security.yml',
        ]);
    }

    expect(repositorySecurityWorkflow('quality')['on']['pull_request']['branches'])->toBe(['main']);
});

it('scans the exact commit through the immutable input-free organization action with read-only authority', function (): void {
    $workflow = repositorySecurityWorkflow('reusable-repository-security');
    $job = $workflow['jobs']['repository-security'];

    expect($workflow['on'])->toBe(['workflow_call' => null])
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($job['permissions'])->toBe(['contents' => 'read'])
        ->and($job['runs-on'])->toBe('ubuntu-latest')
        ->and($job['timeout-minutes'])->toBe(20)
        ->and($job)->not->toHaveKeys(['if', 'continue-on-error', 'env', 'secrets'])
        ->and($job['steps'])->toHaveCount(3)
        ->and($job['steps'][0]['uses'])->toBe('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1')
        ->and($job['steps'][0]['with'])->toBe([
            'ref' => '${{ github.sha }}',
            'persist-credentials' => false,
        ])->and($job['steps'][1])->toBe([
            'name' => 'Scan the checked-out API repository',
            'id' => 'repository-scan',
            'uses' => 'SecPal/.github/.github/actions/trivy-repository-scan@1e0e05ba7ee20e1598172889e942a55d23b0782d',
        ])->and($job['steps'][2]['env'])->toBe([
            'GATE_STATE' => '${{ steps.repository-scan.outputs.gate-state }}',
        ])->and($job['steps'][2])->not->toHaveKeys(['if', 'continue-on-error']);
});

it('accepts only CLEAN without exposing untrusted state in output', function (?string $state, bool $accepted): void {
    $step = repositorySecurityWorkflow('reusable-repository-security')['jobs']['repository-security']['steps'][2];
    $process = new Process(['bash', '-c', $step['run']], null, ['GATE_STATE' => $state ?? false]);
    $process->run();

    expect($process->isSuccessful())->toBe($accepted)
        ->and($process->getOutput().$process->getErrorOutput())->toBe('');
})->with([
    'clean' => ['CLEAN', true],
    'actionable' => ['ACTIONABLE', false],
    'review required' => ['REVIEW_REQUIRED', false],
    'unknown or stale' => ['UNKNOWN_STALE', false],
    'empty' => ['', false],
    'missing' => [null, false],
    'unknown' => ['UNKNOWN', false],
    'malformed' => ["CLEAN\nACTIONABLE", false],
]);
