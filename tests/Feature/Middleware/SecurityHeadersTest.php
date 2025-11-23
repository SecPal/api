<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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

    test('X-XSS-Protection header is set', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->get('X-XSS-Protection'))->toBe('1; mode=block');
    });

    test('Referrer-Policy header is set', function () {
        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    });

    test('Strict-Transport-Security header is not set in non-production', function () {
        if (config('app.env') === 'production') {
            $this->markTestSkipped('This test is for non-production environments only');
        }

        $response = $this->get('/sanctum/csrf-cookie');

        expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    test('all security headers are present on API routes', function () {
        $response = $this->get('/health');

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-XSS-Protection'))->toBe('1; mode=block')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    });
});
