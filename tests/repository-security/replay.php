<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Run the reviewed action itself; scanner implementation remains upstream.
$upstream = realpath($argv[1] ?? '') ?: throw new RuntimeException('Provide the reviewed organization checkout.');
$pin = '1dedfc2147390dd4dd42cb8ff50d4576ce8e461d';
$identity = new Process(['git', '-C', $upstream, 'rev-parse', 'HEAD']);
$identity->mustRun();
$clean = new Process(['git', '-C', $upstream, 'status', '--porcelain']);
$clean->mustRun();
if (trim($identity->getOutput()) !== $pin || $clean->getOutput() !== '') {
    throw new RuntimeException('Organization checkout must be clean at the reviewed scanner pin.');
}

$actionPath = $upstream.'/.github/actions/trivy-repository-scan';
$action = Yaml::parseFile($actionPath.'/action.yml');
$workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/reusable-repository-security.yml');
$acceptance = $workflow['jobs']['repository-security']['steps'][2]['run'];
$root = sys_get_temp_dir().'/secpal-api-scan-'.bin2hex(random_bytes(8));
$workspace = $root.'/workspace';
$runner = $root.'/runner';
mkdir($workspace, 0700, true);
mkdir($runner, 0700);

try {
    // Laravel uses Composer lockfiles, including source development packages.
    file_put_contents($workspace.'/composer.json', json_encode([
        'name' => 'secpal/api-scan-fixture',
        'require-dev' => ['symfony/http-foundation' => '5.4.0'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($workspace.'/composer.lock', json_encode([
        'packages' => [],
        'packages-dev' => [[
            'name' => 'symfony/http-foundation',
            'version' => 'v5.4.0',
            'require' => ['php' => '>=7.2.5'],
        ]],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($workspace.'/Dockerfile', "FROM php:8.4\nUSER root\n");
    // Never retain a complete secret value in source, output, or failure text.
    $secret = 'SECPAL_SYNTHETIC_'.'SECRET_'.strtoupper(bin2hex(random_bytes(8)));
    file_put_contents($workspace.'/.env', 'API_TEST_TOKEN='.$secret."\n");

    foreach ([['init', '--quiet'], ['add', '.'], [
        '-c', 'user.name=SecPal Fixture', '-c', 'user.email=test@secpal.dev',
        '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Synthetic API scanner fixture',
    ]] as $arguments) {
        (new Process(['git', '-C', $workspace, ...$arguments]))->mustRun();
    }
    $head = new Process(['git', '-C', $workspace, 'rev-parse', 'HEAD']);
    $head->mustRun();
    $commit = trim($head->getOutput());
    $environment = [
        ...array_fill_keys(array_keys(getenv()), false),
        'PATH' => getenv('PATH'),
        'HOME' => $root,
        ...$action['runs']['steps'][0]['env'],
        'GITHUB_ACTION_PATH' => $actionPath,
        'GITHUB_WORKSPACE' => $workspace,
        'GITHUB_REPOSITORY' => 'SecPal/api',
        'GITHUB_SHA' => $commit,
        'GITHUB_RUN_ID' => 'api-fixture',
        'GITHUB_RUN_ATTEMPT' => '1',
        'GITHUB_OUTPUT' => $runner.'/outputs',
        'GITHUB_STEP_SUMMARY' => $runner.'/summary',
        'RUNNER_TEMP' => $runner,
        'RUNNER_OS' => 'Linux',
        'RUNNER_ARCH' => 'X64',
    ];
    $scan = new Process(['bash', '-c', $action['runs']['steps'][0]['run']], $workspace, $environment);
    $scan->setTimeout(1200)->run();
    $results = glob($runner.'/secpal-trivy-repository-*/result.json');
    if (! $scan->isSuccessful() || count($results) !== 1) {
        throw new RuntimeException('Shared action did not produce one successful evidence envelope.');
    }
    $evidence = file_get_contents($results[0]);
    $result = json_decode($evidence, true, flags: JSON_THROW_ON_ERROR);
    $public = $evidence.$scan->getOutput().$scan->getErrorOutput()
        .file_get_contents($runner.'/outputs').file_get_contents($runner.'/summary');
    if (str_contains($public, $secret)) {
        throw new RuntimeException('Synthetic secret redaction failed.');
    }
    $secret = null;
    if (glob(dirname($results[0]).'/*') !== [$results[0]]
        || glob($runner.'/secpal-trivy-tool-*') !== []
        || glob($runner.'/secpal-trivy-cache-*') !== []) {
        throw new RuntimeException('Shared action retained unexpected artifact or private scanner material.');
    }
    if ($result['subject'] !== ['commit' => $commit, 'repository' => 'SecPal/api']) {
        throw new RuntimeException('Shared action failed exact API target assertions.');
    }
    if ($result['gate_state'] !== 'ACTIONABLE') {
        throw new RuntimeException('Shared action did not admit actionable API fixtures (failure code: '.($result['operation']['failure_code'] ?? 'none').').');
    }
    if ($result['database']['status'] !== 'FRESH') {
        throw new RuntimeException('Shared action did not produce fresh database evidence.');
    }
    if ($result['operation'] !== ['name' => 'TRIVY_REPOSITORY_SCAN', 'status' => 'SUCCEEDED']
        || $result['scanner']['name'] !== 'trivy'
        || $result['scanner']['version'] !== (string) $action['runs']['steps'][0]['env']['TRIVY_VERSION']
        || $result['scanner']['immutable_id'] !== 'sha256:'.$action['runs']['steps'][0]['env']['TRIVY_ARCHIVE_SHA256']) {
        throw new RuntimeException('Shared action failed operation or immutable scanner identity assertions.');
    }
    foreach ([$result['scanner']['configuration_sha256'], $result['database']['identity'], $result['policy']['sha256']] as $digest) {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new RuntimeException('Shared action omitted a required evidence identity.');
        }
    }
    $paths = [];
    foreach ($result['findings'] as $finding) {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $finding['fingerprint']) !== 1) {
            throw new RuntimeException('Shared action omitted deterministic finding identity.');
        }
        $paths[$finding['class']][] = $finding['path'];
    }
    foreach (['VULNERABILITY' => 'composer.lock', 'MISCONFIGURATION' => 'Dockerfile', 'SECRET' => '.env'] as $class => $path) {
        if (! in_array($path, $paths[$class] ?? [], true)) {
            throw new RuntimeException('Shared action did not detect the required API fixture class: '.$class);
        }
    }
    $gate = new Process(['bash', '-c', $acceptance], null, ['GATE_STATE' => $result['gate_state']]);
    $gate->run();
    if ($gate->isSuccessful()) {
        throw new RuntimeException('API acceptance allowed actionable fixture evidence.');
    }
    echo 'PASS: exact API fixture commit; Composer development dependency, Dockerfile and secret detection; fresh evidence; redacted public output; ACTIONABLE blocked.', PHP_EOL;
    echo 'Scanner revision: '.$pin.'; target commit: '.$commit.'; configuration identity: '.$result['scanner']['configuration_sha256'], PHP_EOL;
} finally {
    (new Filesystem)->deleteDirectory($root);
}
