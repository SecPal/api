<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    if (! Route::has('test.error.403')) {
        Route::get('/__test-error-403', static function (): never {
            abort(403);
        })->name('test.error.403');
    }

    if (! Route::has('test.error.500')) {
        Route::get('/__test-error-500', static function (): never {
            abort(500);
        })->name('test.error.500');
    }

    if (! Route::has('test.error.503')) {
        Route::get('/__test-error-503', static function (): never {
            abort(503);
        })->name('test.error.503');
    }
});

describe('Custom error pages', function () {
    it('renders a branded html 404 page for browser requests', function (): void {
        $response = $this->withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
        ])->get('/diese-seite-gibt-es-nicht');

        $response->assertNotFound();
        expect((string) $response->headers->get('Content-Type'))->toContain('text/html');

        $response->assertSee('SecPal API', false)
            ->assertSee('404', false)
            ->assertSee('Page not found', false)
            ->assertSee('src="/secpal-logo-light.png"', false)
            ->assertSee('srcset="/secpal-logo-dark.png"', false)
            ->assertSeeText("Sorry, we couldn't")
            ->assertDontSee('View status', false)
            ->assertDontSee('Contact support', false)
            ->assertDontSee('Eigenes SecPal-Branding statt Laravel-Standardseite', false)
            ->assertDontSee('Optimiert fur helle und dunkle Systemeinstellungen', false)
            ->assertDontSee('JSON-404-Antworten fur API-Clients bleiben unverandert', false);
    });

    it('renders a branded html 403 page for browser requests', function (): void {
        $response = $this->withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
        ])->get('/__test-error-403');

        $response->assertForbidden();
        expect((string) $response->headers->get('Content-Type'))->toContain('text/html');

        $response->assertSee('403', false)
            ->assertSee('Access forbidden', false)
            ->assertSee('src="/secpal-logo-light.png"', false)
            ->assertSee('srcset="/secpal-logo-dark.png"', false)
            ->assertDontSee('View status', false)
            ->assertDontSee('Contact support', false);
    });

    it('renders a branded html 500 page for browser requests', function (): void {
        $response = $this->withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
        ])->get('/__test-error-500');

        $response->assertStatus(500);
        expect((string) $response->headers->get('Content-Type'))->toContain('text/html');

        $response->assertSee('500', false)
            ->assertSee('Something went wrong', false)
            ->assertSee('src="/secpal-logo-light.png"', false)
            ->assertSee('srcset="/secpal-logo-dark.png"', false)
            ->assertDontSee('View status', false)
            ->assertDontSee('Contact support', false);
    });

    it('renders a branded html 503 page for browser requests', function (): void {
        $response = $this->withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
        ])->get('/__test-error-503');

        $response->assertStatus(503);
        expect((string) $response->headers->get('Content-Type'))->toContain('text/html');

        $response->assertSee('503', false)
            ->assertSee('Service unavailable', false)
            ->assertSee('src="/secpal-logo-light.png"', false)
            ->assertSee('srcset="/secpal-logo-dark.png"', false)
            ->assertDontSee('View status', false)
            ->assertDontSee('Contact support', false);
    });

    it('keeps json 404 responses for api clients', function (): void {
        $response = $this->getJson('/diese-seite-gibt-es-nicht');

        $response->assertNotFound();

        expect($response->json('message'))->toBeString()->toBe('Resource not found.');
        expect((string) $response->headers->get('Content-Type'))->toContain('application/json');
    });
});
