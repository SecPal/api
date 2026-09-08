<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

return [
    'rate_bound' => [
        'per_fingerprint' => 5,
        'global' => 300,
        'decay_seconds' => 60,
    ],
];
