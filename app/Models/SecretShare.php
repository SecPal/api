<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * SecretShare model for managing access control to secrets.
 *
 * A share grants read/write/admin permission to either a user OR a role.
 * XOR constraint enforced at database level.
 *
 * @property string $id UUID primary key
 * @property string $secret_id UUID foreign key to secrets
 * @property ?string $user_id UUID foreign key to users (XOR with role_id)
 * @property ?int $role_id Foreign key to roles (XOR with user_id)
 * @property string $permission Enum: read|write|admin
 * @property string $granted_by UUID foreign key to users (who granted)
 * @property \Illuminate\Support\Carbon $granted_at When share was granted
 * @property ?\Illuminate\Support\Carbon $expires_at Optional expiration
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Secret $secret
 * @property-read ?User $user
 * @property-read ?Role $role
 * @property-read User $granter
 * @property-read bool $is_expired
 */
class SecretShare extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'secret_shares';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'secret_id',
        'user_id',
        'role_id',
        'permission',
        'granted_by',
        'granted_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the secret that this share grants access to.
     *
     * @return BelongsTo<Secret, $this>
     */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(Secret::class);
    }

    /**
     * Get the user that has access (if user-based share).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the role that has access (if role-based share).
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the user who granted this share.
     *
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * Scope to only active (non-expired) shares.
     *
     * @param \Illuminate\Database\Eloquent\Builder<SecretShare> $query
     * @return \Illuminate\Database\Eloquent\Builder<SecretShare>
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Check if this share has expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }
}
