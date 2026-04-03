<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

test('router only registers live custom middleware aliases', function (): void {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('tenant');
    expect($aliases)->toHaveKey('tenant.inject');
    expect($aliases)->toHaveKey('check.organizational.scope');
    expect($aliases)->not->toHaveKey('check.customer.scope');

    // All custom App middleware aliases must point to existing classes
    $deadClasses = [];

    foreach ($aliases as $alias => $class) {
        if (str_starts_with($class, 'App\\Http\\Middleware\\') && ! class_exists($class)) {
            $deadClasses[] = "{$alias} => {$class}";
        }
    }

    expect($deadClasses)->toBeEmpty('Dead middleware class references: '.implode(', ', $deadClasses));
});
