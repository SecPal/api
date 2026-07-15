<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

it('runs markdownlint through npm without pre-commit node environment installation', function (): void {
    $config = file_get_contents(base_path('.pre-commit-config.yaml'));
    $markdownlintHookPattern = '/- id: markdownlint\b(?P<hook>.*?)(?=\n\s*-\s+id:|\n\s*#\s|\n\s*-\s+repo:|\z)/s';

    expect($config)->not->toBeFalse()
        ->and(preg_match($markdownlintHookPattern, $config, $matches))->toBe(1);

    $hook = $matches['hook'];

    expect($hook)->toContain('entry: npx --yes markdownlint-cli@0.49.0 --config .markdownlint.json')
        ->and($hook)->toContain('language: system')
        ->and($hook)->not->toContain('additional_dependencies:');
});

it('runs prettier through npm without pre-commit node environment installation', function (): void {
    $config = file_get_contents(base_path('.pre-commit-config.yaml'));
    $prettierHookPattern = '/- id: prettier\b(?P<hook>.*?)(?=\n\s*-\s+id:|\n\s*#\s|\n\s*-\s+repo:|\z)/s';

    expect($config)->not->toBeFalse()
        ->and($config)->not->toContain('pre-commit/mirrors-prettier')
        ->and(preg_match($prettierHookPattern, $config, $matches))->toBe(1);

    $hook = $matches['hook'];

    expect($hook)->toContain('entry: npx --yes prettier@4.0.0-alpha.8 --write')
        ->and($hook)->toContain('language: system')
        ->and($hook)->toContain('CHANGELOG\\.md')
        ->and($hook)->not->toContain('additional_dependencies:');
});

it('smoke tests the npm-backed hooks with npm 12 on Linux and macOS in CI', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/quality.yml'));

    expect($workflow)->not->toBeFalse()
        ->and($workflow)->toContain('pre-commit-hooks:')
        ->and($workflow)->toContain('runs-on: ${{ matrix.os }}')
        ->and($workflow)->toContain('- ubuntu-latest')
        ->and($workflow)->toContain('- macos-latest')
        ->and($workflow)->toContain('npm install --global npm@12.0.0')
        ->and($workflow)->toContain('pre-commit install-hooks')
        ->and($workflow)->toContain('pre-commit run prettier --files')
        ->and($workflow)->toContain('pre-commit run markdownlint --files');
});
