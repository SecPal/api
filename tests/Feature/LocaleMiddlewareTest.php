<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('middleware sets locale from Accept-Language header to German', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'de',
    ])->get('/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});

test('middleware sets locale from Accept-Language header to English', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'en',
    ])->get('/health');

    expect(App::getLocale())->toBe('en');
    $response->assertOk();
});

test('middleware defaults to configured locale when no Accept-Language header', function (): void {
    $response = $this->get('/health');

    expect(App::getLocale())->toBe(config('app.locale'));
    $response->assertOk();
});

test('middleware defaults to configured locale for unsupported language', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'fr',
    ])->get('/health');

    expect(App::getLocale())->toBe(config('app.locale'));
    $response->assertOk();
});

test('middleware handles complex Accept-Language header with quality values', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
    ])->get('/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});

test('middleware prefers higher quality language from Accept-Language header', function (): void {
    $response = $this->withHeaders([
        'Accept-Language' => 'en;q=0.5,de;q=0.9',
    ])->get('/health');

    expect(App::getLocale())->toBe('de');
    $response->assertOk();
});

test('middleware prefers authenticated user preferred locale over Accept-Language header', function (): void {
    $user = \App\Models\User::factory()->create([
        'preferred_locale' => 'de',
    ]);

    Route::middleware(['api', 'auth:sanctum'])->get('/v1/test-locale', function () {
        return response()->json([
            'locale' => App::getLocale(),
        ]);
    });

    $this->withHeaders(spaCsrfHeaders($this))->postJson('/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $response = $this->withHeaders(spaHeaders([
        'Accept-Language' => 'en',
    ]))->getJson('/v1/test-locale');

    $response->assertOk()
        ->assertJson([
            'locale' => 'de',
        ]);
});
