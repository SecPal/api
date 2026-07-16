<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function preflightScriptContents(): string
{
    $contents = file_get_contents(base_path('scripts/preflight.sh'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

function preflightLegacyLocalContainerToken(): string
{
    return 'd'.'dev';
}

function makePreflightExecutable(string $path, string $contents): void
{
    file_put_contents($path, $contents);
    chmod($path, 0755);
}

function makePreflightFixture(int $parallelExitCode = 0, int $serialExitCode = 9): string
{
    $root = sys_get_temp_dir().'/secpal-preflight-'.bin2hex(random_bytes(8));

    mkdir($root.'/scripts', 0777, true);
    mkdir($root.'/vendor/bin', 0777, true);
    mkdir($root.'/stubs', 0777, true);

    file_put_contents($root.'/composer.json', '{}');
    file_put_contents($root.'/composer.lock', '{}');
    file_put_contents($root.'/commands.log', '');
    file_put_contents($root.'/artisan', '');
    touch($root.'/vendor/.keep');
    copy(base_path('scripts/preflight.sh'), $root.'/scripts/preflight.sh');
    chmod($root.'/scripts/preflight.sh', 0755);

    makePreflightExecutable($root.'/vendor/bin/pint', "#!/usr/bin/env bash\nexit 0\n");
    makePreflightExecutable($root.'/vendor/bin/phpstan', "#!/usr/bin/env bash\nexit 0\n");
    makePreflightExecutable($root.'/stubs/composer', "#!/usr/bin/env bash\necho composer \"\$@\" >> \"$root/commands.log\"\nexit 0\n");
    makePreflightExecutable($root.'/stubs/npx', "#!/usr/bin/env bash\necho npx \"\$@\" >> \"$root/commands.log\"\nexit 0\n");
    makePreflightExecutable($root.'/stubs/reuse', "#!/usr/bin/env bash\necho reuse \"\$@\" >> \"$root/commands.log\"\nexit 0\n");
    makePreflightExecutable($root.'/stubs/php', <<<'BASH'
#!/usr/bin/env bash
echo php "$@" >> COMMAND_LOG
case "$*" in
  *"artisan test --parallel --exclude-group=serial"*)
    exit PARALLEL_EXIT_CODE
    ;;
  *"artisan test --group=serial"*)
    exit SERIAL_EXIT_CODE
    ;;
  *)
    exit 0
    ;;
esac
BASH);
    $phpStub = file_get_contents($root.'/stubs/php');
    expect($phpStub)->not->toBeFalse();
    file_put_contents($root.'/stubs/php', str_replace(
        ['COMMAND_LOG', 'PARALLEL_EXIT_CODE', 'SERIAL_EXIT_CODE'],
        [$root.'/commands.log', (string) $parallelExitCode, (string) $serialExitCode],
        $phpStub,
    ));
    chmod($root.'/stubs/php', 0755);

    exec('git -C '.escapeshellarg($root).' init --quiet');
    exec('git -C '.escapeshellarg($root).' checkout -b feature/preflight --quiet');
    exec('git -C '.escapeshellarg($root).' config user.email preflight@example.test');
    exec('git -C '.escapeshellarg($root).' config user.name "Preflight Test"');
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' commit --quiet -m initial');
    exec('git -C '.escapeshellarg($root).' update-ref refs/remotes/origin/main HEAD');
    exec('git -C '.escapeshellarg($root).' symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main');

    return $root;
}

test('preflight excludes serial tests from the parallel run and executes them separately', function (): void {
    $contents = preflightScriptContents();

    expect($contents)
        ->toContain('php artisan test --parallel --exclude-group=serial || TEST_EXIT=$?')
        ->toContain('php artisan test --group=serial || TEST_EXIT=$?');
});

test('preflight runs PHP tooling directly and fails when enabled tests fail', function (): void {
    $root = makePreflightFixture();

    try {
        $command = sprintf(
            'cd %s && PATH=%s:$PATH PREFLIGHT_RUN_TESTS=1 bash scripts/preflight.sh 2>&1',
            escapeshellarg($root),
            escapeshellarg($root.'/stubs'),
        );

        exec($command, $output, $exitCode);

        $commands = file_get_contents($root.'/commands.log');

        expect($exitCode)->toBe(9)
            ->and($commands)->toContain('php -d memory_limit=512M ./vendor/bin/phpstan analyse')
            ->and($commands)->toContain('php artisan test --parallel --exclude-group=serial')
            ->and($commands)->toContain('php artisan test --group=serial')
            ->and(implode("\n", $output))->not->toContain(strtoupper(preflightLegacyLocalContainerToken()));
    } finally {
        File::deleteDirectory($root);
    }
});

test('preflight preserves a parallel test failure when serial tests pass', function (): void {
    $root = makePreflightFixture(parallelExitCode: 7, serialExitCode: 0);

    try {
        $command = sprintf(
            'cd %s && PATH=%s:$PATH PREFLIGHT_RUN_TESTS=1 bash scripts/preflight.sh 2>&1',
            escapeshellarg($root),
            escapeshellarg($root.'/stubs'),
        );

        exec($command, $output, $exitCode);

        $commands = file_get_contents($root.'/commands.log');

        expect($exitCode)->toBe(7)
            ->and($commands)->toContain('php artisan test --parallel --exclude-group=serial')
            ->and($commands)->toContain('php artisan test --group=serial');
    } finally {
        File::deleteDirectory($root);
    }
});

test('preflight does not contain legacy local-container command routing or guidance', function (): void {
    expect(strtolower(preflightScriptContents()))->not->toContain(preflightLegacyLocalContainerToken());
});

test('preflight excludes gitignored workspace context notes from markdownlint', function (): void {
    expect(preflightScriptContents())
        ->toContain('markdownlint --config .markdownlint.json --dot \'**/*.md\'')
        ->toContain('--ignore-path .gitignore');
});
