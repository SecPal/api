<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    // Disabled until an explicitly owned backend and runtime purpose are supplied.
    'mode' => env('PRIVATE_OBJECT_MODE', 'disabled'),
    'purpose' => env('PRIVATE_OBJECT_PURPOSE'),
    'credential_file' => env('PRIVATE_OBJECT_CREDENTIAL_FILE'),
    'replication_targets' => array_values(array_filter(explode(',', (string) env('PRIVATE_OBJECT_REPLICATION_TARGETS', '')))),
];
