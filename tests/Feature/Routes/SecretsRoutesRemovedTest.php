<?php

// SPDX-FileCopyrightText: 2026 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

describe('Secrets routes removal', function () {
    test('index route is no longer registered', function () {
        $this->getJson('/v1/secrets')->assertNotFound();
    });

    test('nested routes are no longer registered', function () {
        $this->getJson('/v1/secrets/00000000-0000-0000-0000-000000000000/shares')
            ->assertNotFound();

        $this->postJson('/v1/secrets/00000000-0000-0000-0000-000000000000/attachments')
            ->assertNotFound();
    });
});
