<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Merkle Tree Build Frequency
    |--------------------------------------------------------------------------
    |
    | How often to build Merkle trees for ALL activity logs.
    | 'minute' for local development, 'hour' for production batching.
    |
    */

    'merkle_schedule_frequency' => env('MERKLE_SCHEDULE_FREQUENCY', env('APP_ENV') === 'local' ? 'minute' : 'hour'),

];
