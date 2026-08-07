<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

function workflowActionReferences(string $contents): array
{
    preg_match_all(
        '/^(?<indent>[ \\t]*)uses:[ \\t]*(?<action>[^@\\s]+)@(?<reference>[^\\s#]+)(?:[ \\t]+#[^\\r\\n]*)?[ \\t]*$/m',
        $contents,
        $actionReferences,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );

    return $actionReferences;
}

test('the policy recognizes workflow uses lines with inline comments', function (): void {
    $actionReferences = workflowActionReferences(
        '      uses: actions/checkout@v7 # v7.0.1'.PHP_EOL,
    );

    expect($actionReferences)
        ->toHaveCount(1)
        ->and($actionReferences[0]['reference'][0])->toBe('v7');
});

test('the workflow actions in scope use version-documented immutable commit SHAs', function (): void {
    $workflowPaths = [
        '.github/workflows/php-ci.yml',
        '.github/workflows/quality.yml',
        '.github/workflows/live-cors-smoke.yml',
        '.github/workflows/reusable-prettier.yml',
    ];

    foreach ($workflowPaths as $workflowPath) {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$workflowPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read workflow: {$workflowPath}");
        }

        $actionReferences = workflowActionReferences($contents);

        foreach ($actionReferences as $actionReference) {
            $action = $actionReference['action'][0];

            if (str_starts_with($action, './') || str_starts_with($action, 'SecPal/.github/')) {
                continue;
            }

            expect($actionReference['reference'][0])
                ->toMatch('/\\A[0-9a-f]{40}\\z/');

            $precedingContents = substr($contents, 0, $actionReference[0][1]);
            expect($precedingContents)->toMatch(sprintf(
                '/^%s# v?\\d+(?:\\.\\d+)*[ \\t]*\\R$/m',
                preg_quote($actionReference['indent'][0], '/'),
            ));
        }
    }
});
