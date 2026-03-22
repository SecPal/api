<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Qualification model (system + custom qualifications).
 *
 * @property string $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property bool $requires_renewal
 * @property int|null $renewal_period_months
 * @property bool $is_mandatory
 * @property bool $is_system_qualification
 * @property int $sort_order
 */
class Qualification extends Model
{
    /** @use HasFactory<\Database\Factories\QualificationFactory> */
    use EnforcesTenantRouteBinding, HasFactory, HasUuids, SoftDeletes {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'requires_renewal',
        'renewal_period_months',
        'is_mandatory',
        'is_system_qualification',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_renewal' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_system_qualification' => 'boolean',
            'sort_order' => 'integer',
            'renewal_period_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TenantKey, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
    }

    /**
     * @return BelongsToMany<Employee, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_qualifications');
    }

    /**
     * @return HasMany<EmployeeQualification, $this>
     */
    public function employeeQualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class);
    }

    /**
     * Allow route binding to resolve global system qualifications as an explicit
     * exception to the default fail-closed tenant rule.
     */
    protected function routeBindingAllowsGlobalRecords(): bool
    {
        return true;
    }
}
