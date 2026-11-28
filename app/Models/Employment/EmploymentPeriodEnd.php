<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Employment;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $employment_period_id
 * @property string $evidence_id
 * @property CarbonImmutable $valid_until
 * @property CarbonImmutable $recorded_at
 */
final class EmploymentPeriodEnd extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_until' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
