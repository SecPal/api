<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function repositorySecurityWorkflow(string $name): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/'.$name.'.yml');

    return Yaml::parse(preg_replace('/^---\h*$/m', '', $source, 1) ?? $source);
}

it('requires repository security acceptance before publication and provides PR feedback', function (): void {
    $jobs = repositorySecurityWorkflow('publish-container')['jobs'];
    $publisher = $jobs['prepare'];
    $freshQualification = '${{ !inputs.qualified-run && !inputs.qualified-attempt && !inputs.publish-retained }}';

    expect($publisher['needs'])->toBe(['build', 'repository-security'])
        ->and($publisher['if'])->toContain(
            "github.event_name == 'workflow_dispatch'",
            "needs.build.result == 'success' && needs.repository-security.result == 'success'",
            'inputs.publish-retained',
        )
        ->and($jobs['inspect-retained']['permissions'])->toBe(['contents' => 'read', 'actions' => 'read'])
        ->and($publisher)->not->toHaveKey('continue-on-error')
        ->and($jobs['candidate']['needs'])->toBe(['build', 'repository-security'])
        ->and($jobs['candidate'])->not->toHaveKey('continue-on-error');

    foreach (['publish-container', 'quality'] as $name) {
        $workflow = repositorySecurityWorkflow($name);
        $caller = $workflow['jobs']['repository-security'];

        $condition = $name === 'publish-container' ? ['if' => $freshQualification] : [];
        expect($caller)->toBe($condition + [
            'name' => 'Repository Security',
            'permissions' => ['contents' => 'read'],
            'uses' => './.github/workflows/reusable-repository-security.yml',
        ]);
    }

    $download = array_values(array_filter($publisher['steps'], static fn (array $step): bool => $step['name'] === 'Read exact source security evidence'))[0];
    expect($download['with']['name'])->toBe('${{ needs.repository-security.outputs.evidence-artifact }}')
        ->and($download['with'])->not->toHaveKey('pattern')
        ->and($download['if'])->toBe($freshQualification);
    expect(array_keys(repositorySecurityWorkflow('publish-container')['on']))->toBe(['workflow_dispatch', 'push'])
        ->and(repositorySecurityWorkflow('quality')['on'])->toBe([
            'push' => ['branches' => ['main']],
            'pull_request' => ['branches' => ['main']],
        ]);
});

it('schedules source security through the existing gate with no publisher or artifact jobs', function (): void {
    expect(is_file(dirname(__DIR__, 2).'/.github/workflows/daily-repository-security.yml'))->toBeTrue();

    $workflow = repositorySecurityWorkflow('daily-repository-security');

    expect($workflow['on'])->toBe([
        'schedule' => [['cron' => '57 2 * * *']],
        'workflow_dispatch' => null,
    ])->and($workflow['permissions'])->toBe([])
        ->and(array_keys($workflow['jobs']))->toBe(['admit-source', 'repository-security'])
        ->and($workflow['jobs']['admit-source'])->not->toHaveKeys(['if', 'continue-on-error', 'permissions'])
        ->and($workflow['jobs']['admit-source']['steps'])->toHaveCount(1)
        ->and($workflow['jobs']['admit-source']['steps'][0])->not->toHaveKeys(['if', 'continue-on-error'])
        ->and($workflow['jobs']['repository-security'])->toBe([
            'name' => 'Repository Security',
            'needs' => 'admit-source',
            'permissions' => ['contents' => 'read'],
            'uses' => './.github/workflows/reusable-repository-security.yml',
        ]);
});

it('admits only the exact protected API main event source', function (array $changed, bool $accepted): void {
    $step = repositorySecurityWorkflow('daily-repository-security')['jobs']['admit-source']['steps'][0];
    $sha = str_repeat('a', 40);
    $summary = tempnam(sys_get_temp_dir(), 'api-source-assessment-');

    try {
        $process = new Process(['bash', '-eo', 'pipefail', '-c', $step['run']], null, [
            'GITHUB_REPOSITORY' => 'SecPal/api',
            'GITHUB_REF' => 'refs/heads/main',
            'GITHUB_REF_PROTECTED' => 'true',
            'GITHUB_SHA' => $sha,
            ...$changed,
            'GITHUB_STEP_SUMMARY' => $summary,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe($accepted ? 0 : 1)
            ->and($process->getOutput())->toBe('')
            ->and(file_get_contents($summary))->toBe($accepted ? "Source subject: SecPal/api@$sha\n\n" : '');
        if (! $accepted) {
            expect($process->getErrorOutput())->toContain('requires the protected API main event SHA');
        }
    } finally {
        unlink($summary);
    }
})->with([
    'protected API main' => [[], true],
    'wrong repository' => [['GITHUB_REPOSITORY' => 'Other/api'], false],
    'wrong branch' => [['GITHUB_REF' => 'refs/heads/candidate'], false],
    'main tag' => [['GITHUB_REF' => 'refs/tags/main'], false],
    'unprotected main' => [['GITHUB_REF_PROTECTED' => 'false'], false],
    'missing protection' => [['GITHUB_REF_PROTECTED' => false], false],
    'moving ref as source' => [['GITHUB_SHA' => 'main'], false],
    'abbreviated SHA' => [['GITHUB_SHA' => str_repeat('a', 39)], false],
    'missing SHA' => [['GITHUB_SHA' => false], false],
]);

it('keeps direct assessment triggers out of the reusable security gate', function (): void {
    $reusable = repositorySecurityWorkflow('reusable-repository-security');

    expect(array_keys($reusable['on']))->toBe(['workflow_call']);
});

it('scans the exact commit through the immutable input-free organization action with read-only authority', function (): void {
    $workflow = repositorySecurityWorkflow('reusable-repository-security');
    $job = $workflow['jobs']['repository-security'];

    expect($workflow['on']['workflow_call'])->toHaveKey('outputs')->not->toHaveKeys(['inputs', 'secrets'])
        ->and($workflow['on']['workflow_call']['outputs']['evidence-artifact']['value'])->toBe('${{ jobs.repository-security.outputs.evidence-artifact }}')
        ->and($job['outputs']['evidence-artifact'])->toBe('${{ steps.repository-scan.outputs.evidence-artifact }}')
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($job['permissions'])->toBe(['contents' => 'read'])
        ->and($job['runs-on'])->toBe('ubuntu-latest')
        ->and($job['timeout-minutes'])->toBe(20)
        ->and($job)->not->toHaveKeys(['if', 'continue-on-error', 'env', 'secrets'])
        ->and($job['steps'])->toHaveCount(3)
        ->and($job['steps'][0]['uses'])->toMatch('/\Aactions\/checkout@[0-9a-f]{40}\z/')
        ->and($job['steps'][0]['with'])->toBe([
            'ref' => '${{ github.sha }}',
            'persist-credentials' => false,
        ])->and($job['steps'][1])->toHaveCount(3)
        ->and($job['steps'][1]['name'])->toBe('Scan the checked-out API repository')
        ->and($job['steps'][1]['id'])->toBe('repository-scan')
        ->and($job['steps'][1]['uses'])->toMatch('/\ASecPal\/\.github\/\.github\/actions\/trivy-repository-scan@[0-9a-f]{40}\z/')
        ->and($job['steps'][1])->not->toHaveKeys(['if', 'continue-on-error'])
        ->and($job['steps'][2]['env'])->toBe([
            'GATE_STATE' => '${{ steps.repository-scan.outputs.gate-state }}',
        ])->and($job['steps'][2])->not->toHaveKeys(['if', 'continue-on-error']);
});

function withRepositorySecurityReplayFixture(callable $assertions): void
{
    $root = sys_get_temp_dir().'/secpal-replay-policy-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $upstream = $root.'/organization';
    $actionPath = $upstream.'/.github/actions/trivy-repository-scan';
    $workflowPath = $root.'/.github/workflows/reusable-repository-security.yml';
    $replayPath = $root.'/tests/repository-security/replay.php';
    $marker = $root.'/scan-started';

    try {
        $filesystem->makeDirectory($actionPath, 0700, true);
        $filesystem->makeDirectory(dirname($workflowPath), 0700, true);
        $filesystem->makeDirectory(dirname($replayPath), 0700, true);
        $filesystem->copy(dirname(__DIR__).'/repository-security/replay.php', $replayPath);
        symlink(dirname(__DIR__, 2).'/vendor', $root.'/vendor');
        // A local action marks execution without downloading or running a scanner.
        file_put_contents($actionPath.'/action.yml', Yaml::dump([
            'runs' => ['steps' => [['env' => [], 'run' => 'touch '.escapeshellarg($marker)]]],
        ], 6));
        foreach ([['init', '--quiet'], ['add', '.'], [
            '-c', 'user.name=SecPal Fixture', '-c', 'user.email=test@secpal.dev',
            '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Scanner fixture',
        ]] as $arguments) {
            (new Process(['git', '-C', $upstream, ...$arguments]))->mustRun();
        }
        $identity = new Process(['git', '-C', $upstream, 'rev-parse', 'HEAD']);
        $identity->mustRun();
        $pin = trim($identity->getOutput());
        $workflow = repositorySecurityWorkflow('reusable-repository-security');
        $workflow['jobs']['repository-security']['steps'][1]['uses'] = 'SecPal/.github/.github/actions/trivy-repository-scan@'.$pin;
        file_put_contents($workflowPath, Yaml::dump($workflow, 10));

        $assertions($replayPath, $upstream, $workflowPath, $marker, $pin);
    } finally {
        $filesystem->deleteDirectory($root);
    }
}

it('follows workflow scanner pin updates without changing the replay', function (): void {
    withRepositorySecurityReplayFixture(function (string $replay, string $upstream, string $workflowPath, string $marker): void {
        foreach (['initial', 'updated'] as $revision) {
            if ($revision === 'updated') {
                unlink($marker);
                (new Process([
                    'git', '-C', $upstream, '-c', 'user.name=SecPal Fixture',
                    '-c', 'user.email=test@secpal.dev', '-c', 'commit.gpgsign=false',
                    'commit', '--quiet', '--allow-empty', '-m', 'Updated scanner fixture',
                ]))->mustRun();
                $identity = new Process(['git', '-C', $upstream, 'rev-parse', 'HEAD']);
                $identity->mustRun();
                $workflow = Yaml::parseFile($workflowPath);
                $workflow['jobs']['repository-security']['steps'][1]['uses'] = 'SecPal/.github/.github/actions/trivy-repository-scan@'.trim($identity->getOutput());
                file_put_contents($workflowPath, Yaml::dump($workflow, 10));
            }

            $process = new Process([PHP_BINARY, $replay, $upstream]);
            $process->run();

            // Admission succeeds; the stub deliberately emits no scanner evidence.
            expect(file_exists($marker))->toBeTrue()
                ->and($process->isSuccessful())->toBeFalse()
                ->and($process->getOutput().$process->getErrorOutput())->toContain('Shared action did not produce one successful evidence envelope.');
        }
    });
});

it('rejects a mismatched or dirty organization checkout before replaying', function (bool $dirty): void {
    withRepositorySecurityReplayFixture(function (string $replay, string $upstream, string $workflowPath, string $marker) use ($dirty): void {
        if ($dirty) {
            file_put_contents($upstream.'/untracked', 'dirty checkout');
        } else {
            $workflow = Yaml::parseFile($workflowPath);
            $workflow['jobs']['repository-security']['steps'][1]['uses'] = 'SecPal/.github/.github/actions/trivy-repository-scan@'.str_repeat('0', 40);
            file_put_contents($workflowPath, Yaml::dump($workflow, 10));
        }

        $process = new Process([PHP_BINARY, $replay, $upstream]);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and(file_exists($marker))->toBeFalse()
            ->and($process->getOutput().$process->getErrorOutput())->toContain('Organization checkout must be clean at the reviewed scanner pin.');
    });
})->with(['mismatched commit' => false, 'dirty checkout' => true]);

it('rejects a missing, mutable or unexpected scanner reference before replaying', function (?string $reference): void {
    withRepositorySecurityReplayFixture(function (string $replay, string $upstream, string $workflowPath, string $marker, string $pin) use ($reference): void {
        $workflow = Yaml::parseFile($workflowPath);
        $workflow['jobs']['repository-security']['steps'][1]['uses'] = $reference === 'unexpected' ? 'Other/.github/.github/actions/trivy-repository-scan@'.$pin : $reference;
        file_put_contents($workflowPath, Yaml::dump($workflow, 10));
        $process = new Process([PHP_BINARY, $replay, $upstream]);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and(file_exists($marker))->toBeFalse()
            ->and($process->getOutput().$process->getErrorOutput())->toContain('API workflow must pin the organization scanner to a full commit SHA.');
    });
})->with([
    'missing' => null,
    'mutable branch' => 'SecPal/.github/.github/actions/trivy-repository-scan@main',
    'short SHA' => 'SecPal/.github/.github/actions/trivy-repository-scan@abcdef0',
    'unexpected action' => 'unexpected',
]);

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
