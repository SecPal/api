<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Support\Facades\App;

test('middleware sets locale from Accept-Language header to German', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'de',
    ])->get('/api/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});

test('middleware sets locale from Accept-Language header to English', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'en',
    ])->get('/api/health');

    expect(App::getLocale())->toBe('en');
    $response->assertOk();
});

test('middleware defaults to configured locale when no Accept-Language header', function (): void {
    $response = $this->get('/api/health');

    expect(App::getLocale())->toBe(config('app.locale'));
    $response->assertOk();
});

test('middleware defaults to configured locale for unsupported language', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'fr',
    ])->get('/api/health');

    expect(App::getLocale())->toBe(config('app.locale'));
    $response->assertOk();
});

test('middleware handles complex Accept-Language header with quality values', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
    ])->get('/api/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});

test('middleware prefers higher quality language from Accept-Language header', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'en;q=0.5,de;q=0.9',
    ])->get('/api/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});

test('middleware applies to all API routes', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'de',
    ])->get('/api/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});
