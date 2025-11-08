<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * Role Assignment Audit Log
 *
 * Immutable audit trail for role assignment actions.
 * This model is READ-ONLY - records cannot be updated or deleted after creation.
 *
 * @property string $id
 * @property string $user_id
 * @property string $role_id
 * @property string $action
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property string|null $assigned_by
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\User $user
 * @property-read \Spatie\Permission\Models\Role $role
 * @property-read \App\Models\User|null $assignedBy
 */
class RoleAssignmentLog extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'role_assignments_log';

    /**
     * Disable updated_at timestamp (immutable log).
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'role_id',
        'action',
        'valid_from',
        'valid_until',
        'assigned_by',
        'reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Prevent updates to maintain audit trail integrity.
     */
    public function save(array $options = []): bool
    {
        // Allow creation, but prevent updates
        if ($this->exists) {
            return false;
        }

        return parent::save($options);
    }

    /**
     * Prevent deletion to maintain audit trail integrity.
     */
    public function delete(): bool
    {
        return false;
    }

    /**
     * Get the user who received the role assignment.
     *
     * @return BelongsTo<User, RoleAssignmentLog>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the role that was assigned/revoked.
     *
     * @return BelongsTo<Role, RoleAssignmentLog>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the user who performed the assignment action.
     *
     * @return BelongsTo<User, RoleAssignmentLog>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
