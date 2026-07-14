<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

function preflightScriptContents(): string
{
    $contents = file_get_contents(base_path('scripts/preflight.sh'));

    expect($contents)->not->toBeFalse();

    return $contents;
}

test('preflight excludes serial tests from the parallel run and executes them separately', function (): void {
    $contents = preflightScriptContents();

    expect($contents)
        ->toContain('${CMD_PREFIX} php artisan test --parallel --exclude-group=serial || TEST_EXIT=$?')
        ->toContain('${CMD_PREFIX} php artisan test --group=serial || TEST_EXIT=$?');
});

test('preflight excludes gitignored workspace context notes from markdownlint', function (): void {
    expect(preflightScriptContents())
        ->toContain('markdownlint --config .markdownlint.json --dot \'**/*.md\'')
        ->toContain('--ignore .context');

    $npx = (new Symfony\Component\Process\ExecutableFinder)->find('npx');
    if ($npx === null) {
        test()->markTestSkipped('npx is required to verify markdownlint ignore behavior.');

        return;
    }

    $sandbox = sys_get_temp_dir().'/secpal-markdownlint-'.bin2hex(random_bytes(8));
    mkdir($sandbox.'/.context', recursive: true);

    try {
        file_put_contents($sandbox.'/README.md', "# Repository documentation\n");
        file_put_contents($sandbox.'/.context/notes.md', "#Invalid workspace note\n");

        $command = [
            $npx,
            '--yes',
            '--package',
            'markdownlint-cli@0.49.0',
            'markdownlint',
            '--config',
            base_path('.markdownlint.json'),
            '--dot',
            '**/*.md',
            '--ignore',
            '.context',
        ];
        $ignoredContextResult = new Symfony\Component\Process\Process($command, $sandbox);
        $ignoredContextResult->setTimeout(30);
        $ignoredContextResult->run();

        expect($ignoredContextResult->isSuccessful())
            ->toBeTrue($ignoredContextResult->getErrorOutput());

        file_put_contents($sandbox.'/README.md', "#Invalid repository documentation\n");

        $repositoryMarkdownResult = new Symfony\Component\Process\Process($command, $sandbox);
        $repositoryMarkdownResult->setTimeout(30);
        $repositoryMarkdownResult->run();

        expect($repositoryMarkdownResult->isSuccessful())
            ->toBeFalse('The control file must prove that repository Markdown is still linted.');
    } finally {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($sandbox);
    }
});
