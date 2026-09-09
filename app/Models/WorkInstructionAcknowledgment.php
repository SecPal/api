<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $tenant_id
 * @property string $work_instruction_id
 * @property string|null $employee_id
 * @property string $employee_identity_id
 * @property string|null $acknowledged_by_user_id
 * @property \Illuminate\Support\Carbon $acknowledged_at
 * @property-read TenantKey $tenant
 * @property-read WorkInstruction $workInstruction
 * @property-read Employee $employee
 * @property-read User|null $acknowledgedBy
 */
class WorkInstructionAcknowledgment extends Model
{
    /** @use HasFactory<\Database\Factories\WorkInstructionAcknowledgmentFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'work_instruction_id',
        'employee_id',
        'employee_identity_id',
        'acknowledged_by_user_id',
        'acknowledged_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /** @return BelongsTo<WorkInstruction, $this> */
    public function workInstruction(): BelongsTo
    {
        return $this->belongsTo(WorkInstruction::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
