<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

test('notification registration rollout follows the canonical bootstrap schema', function (): void {
    $contract = file_get_contents(app_path('Support/BootstrapContract.php'));

    expect($contract)->toMatch(
        '/NOTIFICATION_REGISTRATION_SCHEMA_VERSIONS\s*=\s*\[\s*3,\s*self::SCHEMA_VERSION,\s*\]/'
    );
});
