<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

function apiGovernanceSurface(string $path): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    expect($contents)->toBeString();

    return $contents;
}

test('API governance delegates generic work graph semantics to the canonical owner', function (): void {
    $baseline = apiGovernanceSurface('AGENTS.md');
    $compatibilityMirror = apiGovernanceSurface('.github/copilot-instructions.md');
    $runtimeOverlay = apiGovernanceSurface('.github/instructions/org-shared.instructions.md');

    foreach ([$baseline, $compatibilityMirror, $runtimeOverlay] as $surface) {
        expect($surface)
            ->toContain('SecPal/.github/docs/work-graph-contract.md')
            ->toContain('single organization-wide')
            ->not->toContain('1 topic = 1 PR = 1 branch')
            ->not->toContain('Every real out-of-scope finding becomes a GitHub issue immediately')
            ->not->toContain('local 4-pass review')
            ->not->toContain('more than one PR; if in doubt');
    }

    expect(strtolower($baseline))
        ->not->toContain('zero issues')
        ->not->toContain('zero newly discoverable');
});

test('API governance preserves framework and security-specific boundaries', function (): void {
    $baseline = apiGovernanceSurface('AGENTS.md');
    $laravelOverlay = apiGovernanceSurface('.github/instructions/php-laravel.instructions.md');

    expect($baseline)
        ->toContain('tenant isolation')
        ->toContain('authorization')
        ->toContain('database constraints')
        ->toContain('`*_plain`')
        ->toContain('`*_idx`')
        ->toContain('`*_enc`')
        ->toContain('Laravel container')
        ->toContain('behavior-preserving refactor')
        ->toContain('governance/docs-only');

    expect($laravelOverlay)
        ->toContain('Form Requests')
        ->toContain('policies and gates')
        ->toContain('database constraints and transactions')
        ->toContain('framework lifecycle APIs')
        ->toContain('Laravel container');
});

test('API review guidance permits evidence-based disposition without mandatory mutation', function (): void {
    $baseline = apiGovernanceSurface('AGENTS.md');

    expect($baseline)
        ->toContain('canonical finding classification')
        ->toContain('dispositioned with concise evidence')
        ->not->toContain('Fix the code, push, and resolve threads');
});
