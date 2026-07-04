<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OpenTimestamp Calendar URLs
    |--------------------------------------------------------------------------
    |
    | Calendar servers for submitting and upgrading timestamp proofs.
    | At least 2 must respond for successful submission.
    |
    | Working pool servers (as of 2025):
    | - https://a.pool.opentimestamps.org
    | - https://b.pool.opentimestamps.org
    | - https://a.pool.eternitywall.com
    | - https://ots.btc.catallaxy.com
    |
    */

    'calendar_urls' => [
        'https://a.pool.opentimestamps.org',
        'https://b.pool.opentimestamps.org',
        'https://a.pool.eternitywall.com',
        'https://ots.btc.catallaxy.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum seconds to wait for calendar server response.
    |
    */

    'timeout' => env('OPENTIMESTAMP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Upgrade Interval
    |--------------------------------------------------------------------------
    |
    | How often to check for pending proof upgrades (seconds).
    | Scheduled via routes/console.php.
    |
    */

    'upgrade_interval' => env('OPENTIMESTAMP_UPGRADE_INTERVAL', 3600), // 1 hour

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
