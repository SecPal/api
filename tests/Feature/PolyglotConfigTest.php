<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Http\Middleware\DisablePolyglotUi;

test('polyglot config exists and is loadable', function () {
    $config = config('polyglot');

    expect($config)
        ->toBeArray('Polyglot config should be an array')
        ->not->toBeEmpty('Polyglot config should not be empty');
});

test('polyglot config has required keys', function () {
    expect(config()->has('polyglot.enabled'))->toBeTrue('Config should have "enabled" entry');
    expect(config()->has('polyglot.locales'))->toBeTrue('Config should have "locales" entry');
    expect(config()->has('polyglot.sources'))->toBeTrue('Config should have "sources" entry');
    expect(config()->has('polyglot.path'))->toBeTrue('Config should have "path" entry');
    expect(config()->has('polyglot.middleware'))->toBeTrue('Config should have "middleware" entry');
});

test('polyglot gettext mode is enabled by default', function () {
    expect(config('polyglot.enabled'))->toBeTrue();
});

test('polyglot locales include english and german', function () {
    $locales = config('polyglot.locales');

    expect($locales)
        ->toBeArray()
        ->toHaveKeys(['en', 'de']);

    expect($locales['en'])->toBeArray()->not->toBeEmpty();
    expect($locales['de'])->toBeArray()->not->toBeEmpty();
});

test('polyglot sources include app, bootstrap, and views paths', function () {
    $sources = config('polyglot.sources');

    expect($sources)
        ->toBeArray('Sources should be an array')
        ->not->toBeEmpty('Sources should not be empty');

    $primarySource = $sources[0] ?? null;

    expect($primarySource)->toBeArray();
    expect($primarySource['include'] ?? null)
        ->toBeArray()
        ->toContain(app_path(), base_path('bootstrap'), resource_path('views'));
});

test('polyglot routes use the production UI blocker middleware', function () {
    expect(config('polyglot.path'))->toBe('polyglot');
    expect(config('polyglot.middleware'))
        ->toBeArray()
        ->toContain('web', DisablePolyglotUi::class);
});

test('custom polyglot service provider is registered', function () {
    $providersFile = file_get_contents(base_path('bootstrap/providers.php'));

    expect($providersFile)
        ->toBeString()
        ->toContain('App\\Providers\\PolyglotServiceProvider::class');
});
