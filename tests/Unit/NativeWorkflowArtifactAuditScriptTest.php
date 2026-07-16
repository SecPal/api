<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function nativeWorkflowLegacyToken(): string
{
    return 'd'.'dev';
}

function makeNativeWorkflowAuditFixture(): string
{
    $root = sys_get_temp_dir().'/secpal-native-workflow-audit-'.Str::uuid();

    mkdir($root, 0777, true);

    return $root;
}

function runNativeWorkflowArtifactAudit(string ...$paths): array
{
    if (! function_exists('proc_open')) {
        test()->markTestSkipped('proc_open is required for native workflow artifact audit coverage.');
    }

    $process = proc_open(
        ['bash', 'scripts/audit-native-workflow-artifacts.sh', ...$paths],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        base_path(),
        [
            'LC_ALL' => 'C',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ],
    );

    expect($process)->toBeResource();

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

test('native workflow artifact audit allows only changelog history', function (): void {
    $root = makeNativeWorkflowAuditFixture();

    try {
        file_put_contents($root.'/CHANGELOG.md', 'Removed '.strtoupper(nativeWorkflowLegacyToken()).' history.');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('Native workflow artifact audit passed');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit rejects legacy local-container artifacts even without matching content', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    $token = nativeWorkflowLegacyToken();

    try {
        mkdir($root.'/.'.$token);
        file_put_contents($root.'/.'.$token.'/config.yaml', 'name: obsolete');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stderr'])->toContain('Active legacy local-container references remain');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit rejects active content references', function (): void {
    $root = makeNativeWorkflowAuditFixture();

    try {
        file_put_contents($root.'/README.md', 'Run '.nativeWorkflowLegacyToken().' start.');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stderr'])->toContain('README.md');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit rejects legacy local-container symlinks', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    $token = nativeWorkflowLegacyToken();

    try {
        mkdir($root.'/native-config');
        file_put_contents($root.'/native-config/config.yaml', 'name: obsolete');
        symlink($root.'/native-config', $root.'/.'.$token);

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stderr'])->toContain('Active legacy local-container references remain');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit reports invalid target paths as search errors', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    File::deleteDirectory($root);

    $result = runNativeWorkflowArtifactAudit($root);

    expect($result['exit_code'])->toBe(2)
        ->and($result['stderr'])->toContain('target is not a directory');
});

test('native workflow artifact audit ignores the absolute parent checkout path', function (): void {
    $parent = sys_get_temp_dir().'/'.nativeWorkflowLegacyToken().'-clean-'.Str::uuid();
    $root = $parent.'/repository';
    mkdir($root, 0777, true);

    try {
        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('Native workflow artifact audit passed');
    } finally {
        File::deleteDirectory($parent);
    }
});

test('native workflow artifact audit rejects underscore-delimited active references', function (): void {
    $root = makeNativeWorkflowAuditFixture();

    try {
        file_put_contents($root.'/.env.example', strtoupper(nativeWorkflowLegacyToken()).'_ENABLED=true');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stderr'])->toContain('.env.example');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit ignores nested dependency directories', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    $dependencyPath = $root.'/packages/example/vendor/package';
    mkdir($dependencyPath, 0777, true);

    try {
        file_put_contents($dependencyPath.'/config.php', 'Run '.nativeWorkflowLegacyToken().' start.');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('Native workflow artifact audit passed');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit ignores workspace context metadata', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    mkdir($root.'/.context');

    try {
        file_put_contents($root.'/.context/'.nativeWorkflowLegacyToken().'-notes.md', 'Historical workspace notes.');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('Native workflow artifact audit passed');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit checks source directories named storage', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    $sourceStorage = $root.'/src/storage';
    mkdir($sourceStorage, 0777, true);

    try {
        file_put_contents($sourceStorage.'/config.ts', 'export const enabled = "'.nativeWorkflowLegacyToken().'";');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stderr'])->toContain('src/storage/config.ts');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit ignores generated root storage', function (): void {
    $root = makeNativeWorkflowAuditFixture();
    $generatedStorage = $root.'/storage/framework';
    mkdir($generatedStorage, 0777, true);

    try {
        file_put_contents($generatedStorage.'/cache.php', 'Run '.nativeWorkflowLegacyToken().' start.');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('Native workflow artifact audit passed');
    } finally {
        File::deleteDirectory($root);
    }
});

test('native workflow artifact audit checks neutral symlink targets', function (): void {
    $root = makeNativeWorkflowAuditFixture();

    try {
        symlink('/tmp/.'.nativeWorkflowLegacyToken().'/config.yaml', $root.'/local-config.yaml');

        $result = runNativeWorkflowArtifactAudit($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stderr'])->toContain('local-config.yaml');
    } finally {
        File::deleteDirectory($root);
    }
});
