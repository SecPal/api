<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Employment;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $employee_id
 * @property string $initial_terms_id
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property string|null $end_evidence_id
 */
final class EmploymentPeriod extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'employee_id', 'initial_terms_id', 'valid_from', 'valid_until', 'end_evidence_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }
}
