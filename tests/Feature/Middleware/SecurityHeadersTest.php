<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Security Headers Middleware', function () {
    test('X-Frame-Options header is set to DENY', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    });

    test('X-Content-Type-Options header is set to nosniff', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    test('X-XSS-Protection header disables the legacy auditor', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->get('X-XSS-Protection'))->toBe('0');
    });

    test('Referrer-Policy header is set', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    });

    test('strict transport security header is set on secure requests', function () {
        $response = $this->withServerVariables([
            'HTTPS' => 'on',
        ])->get('/sanctum/csrf-cookie');

        expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=63072000; includeSubDomains');
    });

    test('api responses include the full hardening baseline', function () {
        $response = $this->get('/health');

        expect($response->headers->get('Content-Security-Policy'))
            ->toBe("default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'")
            ->and($response->headers->get('Permissions-Policy'))
            ->toBe('accelerometer=(), autoplay=(), camera=(), clipboard-read=(), clipboard-write=(), display-capture=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()')
            ->and($response->headers->get('Cross-Origin-Opener-Policy'))
            ->toBe('same-origin')
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))
            ->toBe('same-site')
            ->and($response->headers->get('Cross-Origin-Embedder-Policy'))
            ->toBe('require-corp')
            ->and($response->headers->get('Origin-Agent-Cluster'))
            ->toBe('?1')
            ->and($response->headers->get('X-Permitted-Cross-Domain-Policies'))
            ->toBe('none');
    });

    test('browser-facing html error responses keep the api header baseline', function () {
        $response = $this->withHeaders([
            'Accept' => 'text/html,application/xhtml+xml',
        ])->get('/diese-seite-gibt-es-nicht');

        $response->assertNotFound();

        expect((string) $response->headers->get('Content-Security-Policy'))
            ->toContain("default-src 'none'")
            ->and((string) $response->headers->get('Permissions-Policy'))
            ->toContain('camera=()')
            ->and($response->headers->get('Cross-Origin-Opener-Policy'))
            ->toBe('same-origin')
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))
            ->toBe('same-site')
            ->and($response->headers->get('Cross-Origin-Embedder-Policy'))
            ->toBe('require-corp')
            ->and($response->headers->get('Origin-Agent-Cluster'))
            ->toBe('?1')
            ->and($response->headers->get('X-Permitted-Cross-Domain-Policies'))
            ->toBe('none');
    });

    test('all security headers are present on API routes', function () {
        $response = $this->get('/health');

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-XSS-Protection'))->toBe('0')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    });
});
