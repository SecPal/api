<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function releasedArtifactWorkflow(): array
{
    $path = dirname(__DIR__, 2).'/.github/workflows/released-artifact-security.yml';
    expect(is_file($path))->toBeTrue('Missing released-artifact monitoring adoption.');

    return Yaml::parseFile($path);
}

/** @return array{int, array<string, string>} */
function selectReleasedArtifact(array $workflow, array $request): array
{
    $job = $workflow['jobs']['subject'];
    $step = array_values(array_filter($job['steps'], fn (array $step): bool => ($step['id'] ?? '') === 'subject'))[0];
    $output = tempnam(sys_get_temp_dir(), 'api-monitor-');
    $env = array_merge($job['env'], [
        'GITHUB_REPOSITORY' => 'SecPal/api',
        'GITHUB_REF' => 'refs/heads/main',
        'EVENT' => 'workflow_dispatch',
        'SCHEDULE' => '',
        'PLATFORM' => 'linux/amd64',
        'INDEX_DIGEST' => '',
        'GITHUB_OUTPUT' => $output,
    ], $request);

    try {
        $process = new Process(['python3', '-I', '-c', $step['run']], dirname(__DIR__, 2), $env);
        $process->run();

        return [$process->getExitCode(), array_column(array_map(
            fn (string $line): array => explode('=', $line, 2),
            file($output, FILE_IGNORE_NEW_LINES),
        ), 1, 0)];
    } finally {
        unlink($output);
    }
}

it('uses only the pinned shared scanners and isolated finding triage', function (): void {
    $workflow = releasedArtifactWorkflow();
    $subject = $workflow['jobs']['subject'];
    $scan = $workflow['jobs']['reevaluate'];
    $release = json_decode($subject['env']['MONITORED_RELEASE'], true, 512, JSON_THROW_ON_ERROR);

    expect($workflow['permissions'])->toBe([])
        ->and(array_keys($workflow['on']))->toBe(['schedule', 'workflow_dispatch'])
        ->and(array_keys($workflow['on']['workflow_dispatch']['inputs']))->toBe(['platform', 'index-digest'])
        ->and($subject['permissions'])->toBe([])
        ->and($scan['uses'])->toMatch('/^SecPal\/\.github\/\.github\/workflows\/reusable-vulnerability-reevaluation\.yml@[a-f0-9]{40}$/')
        ->and($scan['needs'])->toBe('subject')
        ->and($scan['permissions'])->toBe(['attestations' => 'read', 'contents' => 'read', 'issues' => 'write', 'packages' => 'read'])
        ->and($scan['with']['artifact-repository'])->toBe('ghcr.io/secpal/api')
        ->and($scan['with']['publisher-repository'])->toBe('SecPal/api')
        ->and($scan['with']['publisher-workflow'])->toBe('SecPal/api/.github/workflows/publish-container.yml')
        ->and($scan['with']['triage'])->toBeTrue()
        ->and($scan)->not->toHaveKeys(['steps', 'secrets', 'continue-on-error', 'strategy'])
        ->and(array_keys($release['platforms']))->toBe(['linux/amd64', 'linux/arm64'])
        ->and($release['index-digest'])->toMatch('/^sha256:[a-f0-9]{64}$/')
        ->and($release['publisher-workflow-sha'])->toMatch('/^[a-f0-9]{40}$/');

    foreach (['index-digest', 'platform-digest', 'platform', 'syft-spdx-artifact', 'publisher-workflow-sha'] as $input) {
        expect($scan['with'][$input])->toBe('${{ needs.subject.outputs.'.$input.' }}');
    }
    foreach ($release['platforms'] as $platform) {
        expect($platform['platform-digest'])->toMatch('/^sha256:[a-f0-9]{64}$/')
            ->and($platform['syft-spdx-artifact'])->toMatch('/^ghcr\.io\/secpal\/api@sha256:[a-f0-9]{64}$/');
    }
});

it('selects both reviewed native subjects daily and by manual exact digest', function (): void {
    $workflow = releasedArtifactWorkflow();
    $release = json_decode($workflow['jobs']['subject']['env']['MONITORED_RELEASE'], true, 512, JSON_THROW_ON_ERROR);
    $scheduled = [];

    foreach ($workflow['on']['schedule'] as $schedule) {
        expect($schedule['cron'])->toMatch('/^[0-9]+ [0-9]+ \\* \\* \\*$/');
        [$exit, $selected] = selectReleasedArtifact($workflow, ['EVENT' => 'schedule', 'SCHEDULE' => $schedule['cron'], 'PLATFORM' => '']);
        expect($exit)->toBe(0);
        $scheduled[] = $selected['platform'];
    }
    expect($scheduled)->toBe(array_keys($release['platforms']));

    foreach ($release['platforms'] as $platform => $subject) {
        foreach (['', $release['index-digest']] as $index) {
            [$exit, $selected] = selectReleasedArtifact($workflow, ['PLATFORM' => $platform, 'INDEX_DIGEST' => $index]);
            expect($exit)->toBe(0)
                ->and($selected)->toBe([
                    'index-digest' => $release['index-digest'],
                    'platform' => $platform,
                    'platform-digest' => $subject['platform-digest'],
                    'syft-spdx-artifact' => $subject['syft-spdx-artifact'],
                    'publisher-workflow-sha' => $release['publisher-workflow-sha'],
                ]);
        }
    }
});

it('rejects candidates and unsupported monitoring contexts before scanning', function (array $request): void {
    [$exit, $selected] = selectReleasedArtifact(releasedArtifactWorkflow(), $request);
    expect($exit)->not->toBe(0)->and($selected)->toBe([]);
})->with([
    'unreviewed index' => [['INDEX_DIGEST' => 'sha256:'.str_repeat('a', 64)]],
    'moving tag' => [['INDEX_DIGEST' => 'latest']],
    'wrong platform' => [['PLATFORM' => 'linux/s390x']],
    'missing platform' => [['PLATFORM' => '']],
    'unknown schedule' => [['EVENT' => 'schedule', 'SCHEDULE' => '* * * * *', 'PLATFORM' => '']],
    'wrong repository' => [['GITHUB_REPOSITORY' => 'Other/api']],
    'wrong source ref' => [['GITHUB_REF' => 'refs/heads/topic']],
    'unsupported event' => [['EVENT' => 'pull_request']],
]);
