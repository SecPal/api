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
    $publisher = repositorySecurityWorkflow('publish-container')['jobs']['prepare'];

    expect($publisher['needs'])->toBe(['build', 'repository-security'])
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

    $download = array_values(array_filter($publisher['steps'], static fn (array $step): bool => $step['name'] === 'Read exact source security evidence'))[0];
    expect($download['with']['name'])->toBe('${{ needs.repository-security.outputs.evidence-artifact }}')
        ->and($download['with'])->not->toHaveKey('pattern');
    expect(repositorySecurityWorkflow('quality')['on']['pull_request']['branches'])->toBe(['main']);
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
