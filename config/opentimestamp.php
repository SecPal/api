<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
    | Official calendars:
    | - https://alice.btc.calendar.opentimestamps.org
    | - https://bob.btc.calendar.opentimestamps.org
    | - https://finney.calendar.eternitywall.com
    |
    */

    'calendar_urls' => [
        'https://alice.btc.calendar.opentimestamps.org',
        'https://bob.btc.calendar.opentimestamps.org',
        'https://finney.calendar.eternitywall.com',
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
    | Scheduled via app/Console/Kernel.php.
    |
    */

    'upgrade_interval' => env('OPENTIMESTAMP_UPGRADE_INTERVAL', 3600), // 1 hour

];
