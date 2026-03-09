<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EmployeeQualification pivot model.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $qualification_id
 * @property \Illuminate\Support\Carbon $obtained_date
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string|null $certificate_number
 * @property string|null $issuing_authority
 * @property string|null $notes
 * @property string|null $document_path
 * @property string $status
 */
class EmployeeQualification extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeQualificationFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    public const STATUS_ACTIVE = 'valid';

    public const STATUS_EXPIRING = 'expiring_soon';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    protected $fillable = [
        'employee_id',
        'qualification_id',
        'obtained_date',
        'expiry_date',
        'certificate_number',
        'issuing_authority',
        'notes',
        'document_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'obtained_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Qualification, $this>
     */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function applyTenantRouteBindingConstraint(Builder $query, int $tenantId): Builder
    {
        return $query->whereHas('employee', function (Builder $employeeQuery) use ($tenantId): void {
            $employeeQuery->where('tenant_id', $tenantId);
        });
    }
}
