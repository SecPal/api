<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

test('environment is testing', function (): void {
    expect(config('app.env'))->toBe('testing');
});
