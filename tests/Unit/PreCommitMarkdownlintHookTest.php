<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

it('runs markdownlint in a pre-commit managed node environment', function (): void {
    $config = file_get_contents(base_path('.pre-commit-config.yaml'));
    $markdownlintHookPattern = '/- id: markdownlint\b(?P<hook>.*?)(?=\n\s*-\s+id:|\n\s*#\s|\n\s*-\s+repo:|\z)/s';

    expect($config)->not->toBeFalse()
        ->and(preg_match($markdownlintHookPattern, $config, $matches))->toBe(1);

    $hook = $matches['hook'];

    expect($hook)->toContain('language: node')
        ->and($hook)->toContain('additional_dependencies:')
        ->and($hook)->toContain('- markdownlint-cli@0.49.0')
        ->and($hook)->not->toContain('language: system');
});
