<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

test('translation config exists and is loadable', function () {
    $config = config('translation');

    expect($config)
        ->toBeArray('Translation config should be an array')
        ->not->toBeEmpty('Translation config should not be empty');
});

test('translation config has required keys', function () {
    expect(config()->has('translation.key'))->toBeTrue('Config should have "key" entry');
    expect(config()->has('translation.source_locale'))->toBeTrue('Config should have "source_locale" entry');
    expect(config()->has('translation.target_locales'))->toBeTrue('Config should have "target_locales" entry');
    expect(config()->has('translation.gettext_parse_paths'))->toBeTrue('Config should have "gettext_parse_paths" entry');
});

test('source locale is english', function () {
    $sourceLocale = config('translation.source_locale');

    expect($sourceLocale)->toBe('en', 'Source locale should be English (en)');
});

test('target locales include german', function () {
    $targetLocales = config('translation.target_locales');

    expect($targetLocales)
        ->toBeArray()
        ->toContain('de');
});

test('gettext parse paths are valid directories', function () {
    $parsePaths = config('translation.gettext_parse_paths');

    expect($parsePaths)
        ->toBeArray('Parse paths should be an array')
        ->not->toBeEmpty('Parse paths should not be empty');

    foreach ($parsePaths as $path) {
        $fullPath = base_path($path);
        expect($fullPath)->toBeDirectory("Parse path '{$path}' should exist as directory at {$fullPath}");
    }
});

test('gettext parse paths include app and resources', function () {
    $parsePaths = config('translation.gettext_parse_paths');

    expect($parsePaths)
        ->toContain('app')
        ->toContain('resources');
});

test('api key is loaded from environment', function () {
    // In test environment, TRANSLATIONIO_KEY might not be set
    // This test verifies that the config attempts to load it from env
    $apiKey = config('translation.key');

    // Should be null in test environment unless explicitly set
    // The important thing is that it reads from env() without error
    expect($apiKey === null || is_string($apiKey))
        ->toBeTrue('API key should be null or string when loaded from environment');
});

test('translation config structure matches expected format', function () {
    $config = config('translation');

    // Verify structure
    expect($config)
        ->toHaveKey('key')
        ->toHaveKey('source_locale')
        ->toHaveKey('target_locales')
        ->toHaveKey('gettext_parse_paths');

    // Verify types
    expect($config['key'] === null || is_string($config['key']))->toBeTrue();
    expect($config['source_locale'])->toBeString();
    expect($config['target_locales'])->toBeArray();
    expect($config['gettext_parse_paths'])->toBeArray();
});
