<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function makeDomainPolicyCheckFixture(): string
{
    $root = sys_get_temp_dir().'/secpal-domain-policy-check-'.Str::uuid();

    mkdir($root, 0777, true);

    return $root;
}

function retiredChangelogHost(): string
{
    return 'changelog.'.'sec'.'pal.app';
}

function runDomainPolicyCheck(string $root): array
{
    if (! function_exists('proc_open')) {
        test()->markTestSkipped('proc_open is required for domain policy check coverage.');
    }

    $process = proc_open(
        ['bash', base_path('scripts/check-domains.sh')],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        ['LC_ALL' => 'C'],
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

test('domain policy check rejects the retired standalone changelog host', function (): void {
    $root = makeDomainPolicyCheckFixture();

    try {
        $host = retiredChangelogHost();
        file_put_contents($root.'/README.md', 'https://'.$host.'/releases');

        $result = runDomainPolicyCheck($root);

        expect($result['exit_code'])->toBe(1)
            ->and($result['stdout'])->toContain('Domain Policy Check FAILED')
            ->and($result['stdout'])->toContain($host);
    } finally {
        File::deleteDirectory($root);
    }
});

test('domain policy check allows active SecPal hosts', function (): void {
    $root = makeDomainPolicyCheckFixture();

    try {
        file_put_contents($root.'/README.md', 'https://api.secpal.dev/health');

        $result = runDomainPolicyCheck($root);

        expect($result['exit_code'])->toBe(0)
            ->and($result['stdout'])->toContain('Domain Policy Check PASSED');
    } finally {
        File::deleteDirectory($root);
    }
});
