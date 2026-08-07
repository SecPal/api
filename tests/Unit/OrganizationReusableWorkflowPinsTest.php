<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

function organizationReusableWorkflowReferencePattern(string $workflowName): string
{
    return sprintf(
        '/^\\s*uses:\\s*SecPal\\/\\.github\\/\\.github\\/workflows\\/%s@[0-9a-f]{40}\\s*$/m',
        preg_quote($workflowName, '/'),
    );
}

test('organization reusable workflow references use expected paths and immutable commit SHAs', function (): void {
    $workflowReferences = [
        '.github/workflows/check-conflict-markers.yml' => ['reusable-check-conflict-markers.yml'],
        '.github/workflows/dependabot-auto-merge.yml' => ['reusable-dependabot-auto-merge.yml'],
        '.github/workflows/php-ci.yml' => ['reusable-php-lint.yml', 'reusable-php-stan.yml'],
        '.github/workflows/pr-size.yml' => ['reusable-pr-size.yml'],
        '.github/workflows/project-automation.yml' => ['project-automation-core.yml', 'draft-pr-reminder.yml'],
        '.github/workflows/quality.yml' => [
            'reusable-reuse.yml',
            'reusable-markdown-lint.yml',
            'reusable-ai-instructions.yml',
            'reusable-php-lint.yml',
            'reusable-php-stan.yml',
        ],
    ];

    foreach ($workflowReferences as $workflowPath => $reusableWorkflowNames) {
        $contents = file_get_contents(base_path($workflowPath));

        if ($contents === false) {
            throw new RuntimeException("Unable to read workflow: {$workflowPath}");
        }

        foreach ($reusableWorkflowNames as $reusableWorkflowName) {
            expect($contents)->toMatch(
                organizationReusableWorkflowReferencePattern($reusableWorkflowName),
            );
        }
    }
});

test('organization reusable workflow references allow immutable SHA updates', function (): void {
    expect('uses: SecPal/.github/.github/workflows/reusable-php-lint.yml@bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->toMatch(
        organizationReusableWorkflowReferencePattern('reusable-php-lint.yml'),
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
