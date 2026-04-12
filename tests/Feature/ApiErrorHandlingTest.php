<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

it('returns the normalized JSON 404 payload for unknown api routes regardless of debug mode', function (bool $debug): void {
    config(['app.debug' => $debug]);

    $response = $this->getJson('/v1/nonexistent-path');

    $response->assertNotFound()
        ->assertExactJson([
            'message' => 'Resource not found.',
        ]);

    expect($response->headers->get('content-type'))->toContain('application/json');
})->with([true, false]);
