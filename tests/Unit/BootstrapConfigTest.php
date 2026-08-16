<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

test('public bootstrap is disabled by default until deployment values are configured', function (): void {
    expect(config('bootstrap.public_enabled'))->toBeFalse();
});

test('minimum_supported_app_build config is null by default when env var is not set', function (): void {
    expect(config('bootstrap.minimum_supported_app_build'))->toBeNull();
});

test('minimum_supported_app_version config is null by default when env var is not set', function (): void {
    expect(config('bootstrap.minimum_supported_app_version'))->toBeNull();
});
