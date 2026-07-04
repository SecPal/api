<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Tests\Support\TestCaseBootstrapEnvironmentProbe;

test('bootstrap environment probe support class autoloads', function (): void {
    expect(class_exists(TestCaseBootstrapEnvironmentProbe::class))->toBeTrue();
});
