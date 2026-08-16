<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * @return list<array{action: string, reference: string, uses: string, occurrence: int}>
 */
function workflowActionReferences(string $contents): array
{
    $parseableContents = preg_replace(
        '/\A((?:(?:[ \t]*#[^\r\n]*|[ \t]*)\R)*)---[ \t]*(?:\R|\z)/',
        '$1',
        $contents,
        1,
    );

    if ($parseableContents === null) {
        throw new RuntimeException('Unable to normalize the workflow document marker.');
    }

    $workflow = Yaml::parse($parseableContents);

    if (! is_array($workflow)) {
        return [];
    }

    $usesValues = [];

    foreach ($workflow['jobs'] ?? [] as $job) {
        if (! is_array($job)) {
            continue;
        }

        if (array_key_exists('uses', $job)) {
            $usesValues[] = $job['uses'];
        }

        foreach ($job['steps'] ?? [] as $step) {
            if (is_array($step) && array_key_exists('uses', $step)) {
                $usesValues[] = $step['uses'];
            }
        }
    }

    $actionReferences = [];
    $occurrences = [];

    foreach ($usesValues as $uses) {
        if (! is_string($uses)) {
            throw new RuntimeException('Workflow uses values must be strings.');
        }

        $separatorOffset = strrpos($uses, '@');
        $action = $separatorOffset === false ? $uses : substr($uses, 0, $separatorOffset);
        $reference = $separatorOffset === false ? '' : substr($uses, $separatorOffset + 1);
        $occurrence = $occurrences[$uses] ?? 0;
        $occurrences[$uses] = $occurrence + 1;

        $actionReferences[] = [
            'action' => $action,
            'reference' => $reference,
            'uses' => $uses,
            'occurrence' => $occurrence,
        ];
    }

    return $actionReferences;
}

/**
 * @return list<array{indent: string, line: int}>
 */
function workflowActionSourceLines(string $contents, string $uses): array
{
    $sourceLines = [];
    $lines = preg_split('/\\R/', $contents);

    if ($lines === false) {
        throw new RuntimeException('Unable to split workflow contents into lines.');
    }

    $pattern = sprintf(
        '/^(?<indent>[ \\t]*)(?:-[ \\t]*(?:\\{[^}\\r\\n]*?)?)?uses[ \\t]*:[ \\t]*["\\\']?%s["\\\']?(?=[ \\t]*(?:[,}#]|\\z))/',
        preg_quote($uses, '/'),
    );

    foreach ($lines as $lineNumber => $line) {
        if (preg_match($pattern, $line, $matches) === 1) {
            $sourceLines[] = [
                'indent' => $matches['indent'],
                'line' => $lineNumber,
            ];
        }
    }

    return $sourceLines;
}

/**
 * @param  array{action: string, reference: string, uses: string, occurrence: int}  $actionReference
 */
function workflowActionHasAdjacentVersionComment(string $contents, array $actionReference): bool
{
    $lines = preg_split('/\\R/', $contents);

    if ($lines === false) {
        throw new RuntimeException('Unable to split workflow contents into lines.');
    }

    $sourceLines = workflowActionSourceLines($contents, $actionReference['uses']);
    $sourceLine = $sourceLines[$actionReference['occurrence']] ?? null;

    if ($sourceLine === null || $sourceLine['line'] === 0) {
        return false;
    }

    return preg_match(
        '/\\A'.preg_quote($sourceLine['indent'], '/').'# v?\\d+(?:\\.\\d+)*[ \\t]*\\z/',
        $lines[$sourceLine['line'] - 1],
    ) === 1;
}

/**
 * @return list<string>
 */
function workflowActionPinningViolations(string $contents): array
{
    $actionReferences = workflowActionReferences($contents);
    $referenceCounts = array_count_values(array_column($actionReferences, 'uses'));
    $violations = [];

    foreach ($actionReferences as $actionReference) {
        $action = $actionReference['action'];

        if (str_starts_with($action, './')) {
            continue;
        }

        if (preg_match('/\A[0-9a-f]{40}\z/', $actionReference['reference']) !== 1) {
            $violations[] = "{$actionReference['uses']} is not pinned to a full lowercase commit SHA.";

            continue;
        }

        if (str_starts_with($action, 'SecPal/.github/')) {
            continue;
        }

        $sourceLines = workflowActionSourceLines($contents, $actionReference['uses']);

        if (count($sourceLines) !== $referenceCounts[$actionReference['uses']]) {
            $violations[] = "{$actionReference['uses']} cannot be mapped unambiguously to its source line.";

            continue;
        }

        if (! workflowActionHasAdjacentVersionComment($contents, $actionReference)) {
            $violations[] = "{$actionReference['uses']} does not have an immediately preceding version comment.";
        }
    }

    return $violations;
}

test('the policy recognizes workflow uses lines with inline comments', function (): void {
    $actionReferences = workflowActionReferences(<<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - uses: actions/checkout@v7 # v7.0.1
        YAML);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and($actionReferences[0]['reference'])->toBe('v7');
});

test('the policy recognizes action-only step shorthand', function (): void {
    $actionReferences = workflowActionReferences(<<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - uses: actions/checkout@v7
        YAML);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and($actionReferences[0]['reference'])->toBe('v7');
});

test('the policy recognizes flow-mapping action steps', function (): void {
    $actionReferences = workflowActionReferences(<<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - { uses: actions/checkout@v7 }
        YAML);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and($actionReferences[0]['reference'])->toBe('v7');
});

test('the policy recognizes block-scalar action references', function (): void {
    $actionReferences = workflowActionReferences(<<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - uses: >-
                  actions/checkout@v7
        YAML);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and($actionReferences[0]['reference'])->toBe('v7');
});

test('the policy ignores uses-like text in script blocks and action inputs', function (): void {
    $actionReferences = workflowActionReferences(<<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - run: |
                  uses: example/script-text@v1
              - uses: ./local-action
                with:
                  uses: example/input-value@v1
        YAML);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and($actionReferences[0]['action'])->toBe('./local-action');
});

test('the policy rejects moving references in every supported YAML form', function (): void {
    $violations = workflowActionPinningViolations(<<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - uses: actions/checkout@v7
              - { uses: actions/setup-node@v7 }
              - uses: >-
                  actions/cache@v6
        YAML);

    expect($violations)->toHaveCount(3);
});

test('the policy rejects moving organization reusable workflow references', function (): void {
    $violations = workflowActionPinningViolations(<<<'YAML'
        jobs:
          lint:
            uses: SecPal/.github/.github/workflows/reusable-php-lint.yml@main
        YAML);

    expect($violations)->toHaveCount(1);
});

test('the policy requires the version comment immediately before the action reference', function (): void {
    $contents = <<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - name: Checkout repository
                # v7.0.1

                id: checkout
                uses: actions/checkout@0123456789abcdef0123456789abcdef01234567
        YAML;
    $actionReferences = workflowActionReferences($contents);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and(workflowActionHasAdjacentVersionComment($contents, $actionReferences[0]))->toBeFalse()
        ->and(workflowActionPinningViolations($contents))->toHaveCount(1);
});

test('the policy accepts an immediately preceding version comment', function (): void {
    $contents = <<<'YAML'
        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - name: Checkout repository
                # v7.0.1
                uses: actions/checkout@0123456789abcdef0123456789abcdef01234567
        YAML;
    $actionReferences = workflowActionReferences($contents);

    expect($actionReferences)
        ->toHaveCount(1)
        ->and(workflowActionHasAdjacentVersionComment($contents, $actionReferences[0]))->toBeTrue()
        ->and(workflowActionPinningViolations($contents))->toBe([]);
});

test('repository workflows use version-documented immutable commit SHAs', function (): void {
    $workflowDirectory = dirname(__DIR__, 2).'/.github/workflows';
    $workflowPaths = array_merge(
        glob($workflowDirectory.'/*.yml') ?: [],
        glob($workflowDirectory.'/*.yaml') ?: [],
    );
    sort($workflowPaths);

    expect($workflowPaths)->not->toBeEmpty();

    foreach ($workflowPaths as $workflowPath) {
        $contents = file_get_contents($workflowPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read workflow: {$workflowPath}");
        }

        expect(workflowActionPinningViolations($contents))->toBe([]);
    }
});
