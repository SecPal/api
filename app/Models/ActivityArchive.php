<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ActivityArchive model stub (will be implemented in PR-8).
 *
 * This is a temporary stub to satisfy PHPStan and tests
 * in PR-2. Full implementation coming in PR-8.
 *
 * @see Issue #392 PR-8: Create ActivityArchive model & retention commands
 */
class ActivityArchive extends Model
{
    protected $table = 'activity_log_archive';

    protected $fillable = [
        'tenant_id',
        'log_name',
        'created_at',
        'event_hash',
        'previous_hash',
        'merkle_batch_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
