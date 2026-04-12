<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('returns the normalized JSON 404 payload for unknown api routes regardless of debug mode', function (bool $debug): void {
    config(['app.debug' => $debug]);

    $response = $this->getJson('/v1/nonexistent-path');

    $response->assertNotFound()
        ->assertExactJson([
            'message' => 'Resource not found.',
        ]);

    expect($response->headers->get('content-type'))->toContain('application/json');
})->with([true, false]);

it('returns the normalized JSON 500 payload for unexpected api exceptions regardless of debug mode', function (bool $debug): void {
    config(['app.debug' => $debug]);

    Route::middleware('api')->get('/v1/test-runtime-exception', function (): never {
        throw new RuntimeException('Sensitive failure details');
    });

    $response = $this->getJson('/v1/test-runtime-exception');

    $response->assertStatus(500)
        ->assertExactJson([
            'message' => 'Internal server error.',
        ]);

    expect($response->headers->get('content-type'))->toContain('application/json');
})->with([true, false]);
