<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * Temporal Role Assignment Pivot Model
 *
 * Extends Spatie's model_has_roles pivot table with temporal validity periods
 * and audit trail capabilities for time-limited role assignments.
 *
 * @property \Carbon\Carbon|null $valid_from
 * @property \Carbon\Carbon|null $valid_until
 * @property bool $auto_revoke
 * @property string|null $assigned_by
 * @property string|null $reason
 */
class TemporalRoleUser extends MorphPivot
{
    /**
     * The table associated with the model.
     */
    protected $table = 'model_has_roles';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'model_type',
        'model_id',
        'team_id',
        'valid_from',
        'valid_until',
        'auto_revoke',
        'assigned_by',
        'reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'auto_revoke' => 'boolean',
        ];
    }

    /**
     * Scope to filter only currently active (non-expired) role assignments.
     *
     * A role is active if:
     * - valid_from is null OR valid_from <= now
     * - valid_until is null OR valid_until > now
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        /** @var Builder<self> */
        return self::applyActiveFilter($query);
    }

    /**
     * Apply temporal filtering to any query builder.
     *
     * Can be used in both pivot queries and relationship queries.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @param  string  $tablePrefix  Optional table prefix (e.g., 'model_has_roles.')
     * @return Builder<TModel>
     */
    public static function applyActiveFilter(Builder $query, string $tablePrefix = ''): Builder
    {
        $now = Carbon::now();

        return $query->where(function (Builder $q) use ($now, $tablePrefix) {
            $q->whereNull("{$tablePrefix}valid_from")
                ->orWhere("{$tablePrefix}valid_from", '<=', $now);
        })->where(function (Builder $q) use ($now, $tablePrefix) {
            $q->whereNull("{$tablePrefix}valid_until")
                ->orWhere("{$tablePrefix}valid_until", '>', $now);
        });
    }

    /**
     * Scope to filter expired role assignments ready for revocation.
     *
     * Returns roles where:
     * - valid_until is not null
     * - valid_until <= now
     * - auto_revoke is true
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('valid_until')
            ->where('valid_until', '<=', Carbon::now())
            ->where('auto_revoke', true);
    }

    /**
     * Check if this role assignment is currently active.
     */
    public function isActive(): bool
    {
        $now = Carbon::now();

        $validFromCheck = $this->valid_from === null || $this->valid_from->lte($now);
        $validUntilCheck = $this->valid_until === null || $this->valid_until->gt($now);

        return $validFromCheck && $validUntilCheck;
    }

    /**
     * Check if this role assignment has expired.
     */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lte(Carbon::now());
    }

    /**
     * Get the user who assigned this role.
     */
    public function assignedBy(): ?User
    {
        if ($this->assigned_by === null) {
            return null;
        }

        /** @var User|null */
        return User::find($this->assigned_by);
    }
}
