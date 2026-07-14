<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

test('draft reminder only runs for pull request events', function (): void {
    $contents = file_get_contents(base_path('.github/workflows/project-automation.yml'));

    expect($contents)
        ->not->toBeFalse()
        ->toContain(implode("\n", [
            '  draft-reminder:',
            '    name: Draft PR Reminder',
            "    if: github.event_name == 'pull_request'",
        ]));
});
