<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Organization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $employee_id
 * @property string $employment_period_id
 * @property string $establishment_id
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property-read int|null $resolved_version_id
 */
final class EmployeeEstablishmentAssignment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'employee_id', 'employment_period_id', 'establishment_id', 'valid_from', 'valid_until'];

    /** @var list<string> */
    protected $hidden = ['effective_range', 'cancelled_at', 'resolved_version_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }
}
