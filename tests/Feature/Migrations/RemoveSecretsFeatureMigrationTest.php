<?php

// SPDX-FileCopyrightText: 2026 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

it('removes Secrets migrations entirely in 0.x', function () {
    expect(file_exists(database_path('migrations/2025_11_16_023440_create_secrets_table.php')))->toBeFalse();
    expect(file_exists(database_path('migrations/2025_11_16_110234_create_secret_attachments_table.php')))->toBeFalse();
    expect(file_exists(database_path('migrations/2025_11_16_164313_create_secret_shares_table.php')))->toBeFalse();
    expect(file_exists(database_path('migrations/2026_03_15_000000_remove_secrets_feature_tables.php')))->toBeFalse();
});
