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
                '/uses:\\s*SecPal\\/\\.github\\/\\.github\\/workflows\\/%s@[A-Fa-f0-9]{40}(?:\\s|$)/m',
                preg_quote($reusableWorkflowName, '/'),
            ));
        }
    }
});
