<?php

// SPDX-FileCopyrightText: 2026 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

describe('Secrets routes removal', function () {
    test('no Secrets routes are registered', function () {
        $secretRouteUris = collect(app('router')->getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->filter(static fn (string $uri): bool => str_starts_with($uri, 'v1/secrets'))
            ->values()
            ->all();

        expect($secretRouteUris)->toBeEmpty();
    });
});
