<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

test('draft reminder only runs for pull request events', function (): void {
    $contents = file_get_contents(base_path('.github/workflows/project-automation.yml'));

    expect($contents)->not->toBeFalse();

    $normalizedContents = str_replace(["\r\n", "\r"], "\n", $contents);
    $jobWasFound = preg_match(
        '/^  draft-reminder:\n(?<job>(?: {4}.*(?:\n|$))*)/m',
        $normalizedContents,
        $matches,
    );

    expect($jobWasFound)->toBe(1)
        ->and($matches['job'])
        ->toMatch("/^    if: github\\.event_name == 'pull_request'$/m");
});
