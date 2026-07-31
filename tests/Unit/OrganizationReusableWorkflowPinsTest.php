<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

test('the six organization reusable workflow references use immutable commit SHAs', function (): void {
    $workflowReferences = [
        '.github/workflows/check-conflict-markers.yml' => ['reusable-check-conflict-markers.yml'],
        '.github/workflows/php-ci.yml' => ['reusable-php-lint.yml', 'reusable-php-stan.yml'],
        '.github/workflows/pr-size.yml' => ['reusable-pr-size.yml'],
        '.github/workflows/project-automation.yml' => ['project-automation-core.yml', 'draft-pr-reminder.yml'],
    ];

    foreach ($workflowReferences as $workflowPath => $reusableWorkflowNames) {
        $contents = file_get_contents(base_path($workflowPath));

        if ($contents === false) {
            throw new RuntimeException("Unable to read workflow: {$workflowPath}");
        }

        foreach ($reusableWorkflowNames as $reusableWorkflowName) {
            expect($contents)->toMatch(sprintf(
                '/^\\s*uses:\\s*SecPal\\/\\.github\\/\\.github\\/workflows\\/%s@[A-Fa-f0-9]{40}\\s*$/m',
                preg_quote($reusableWorkflowName, '/'),
            ));
        }
    }
});

test('the Dependabot auto-merge reusable workflow reference uses the approved immutable commit SHA', function (): void {
    $workflowPath = '.github/workflows/dependabot-auto-merge.yml';
    $contents = file_get_contents(base_path($workflowPath));

    if ($contents === false) {
        throw new RuntimeException("Unable to read workflow: {$workflowPath}");
    }

    expect($contents)->toMatch(
        '/^\\s*uses:\\s*SecPal\\/\\.github\\/\\.github\\/workflows\\/reusable-dependabot-auto-merge\\.yml@d90b56d4bca7c0d6e7fe1520d69b1f98eca22f5e\\s*$/m',
    );
});

test('the advisory PR-size regression is part of the regular Pest suite', function (): void {
    exec(
        'bash '.escapeshellarg(base_path('tests/pr-size-advisory.sh')).' 2>&1',
        $output,
        $exitCode,
    );

    expect($exitCode)->toBe(0, implode("\n", $output));
});
