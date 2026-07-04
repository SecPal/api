<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

test('health endpoint returns ok', function (): void {
    $this->get('/health')
        ->assertStatus(200)
        ->assertJson([
            'status' => 'ok',
        ]);
});
