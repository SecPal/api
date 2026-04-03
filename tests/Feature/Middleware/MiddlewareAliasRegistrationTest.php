<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

test('router only registers live custom middleware aliases', function (): void {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('tenant');
    expect($aliases)->toHaveKey('tenant.inject');
    expect($aliases)->toHaveKey('check.organizational.scope');
    expect($aliases)->not->toHaveKey('check.customer.scope');
});
