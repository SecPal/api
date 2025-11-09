<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The guard name for Spatie Laravel-Permission.
     * Must match the authentication guard used in routes (sanctum).
     *
     * @var string
     */
    protected $guard_name = 'sanctum';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Override Spatie's roles() relationship to use custom temporal pivot.
     *
     * This enables time-limited role assignments with automatic expiration.
     * Only currently active roles are returned by default.
     *
     * @return MorphToMany<\Spatie\Permission\Models\Role, $this, TemporalRoleUser>
     */
    public function roles(): MorphToMany
    {
        /** @var class-string<\Spatie\Permission\Models\Role> $roleClass */
        $roleClass = config('permission.models.role');

        /** @var string $tableName */
        $tableName = config('permission.table_names.model_has_roles');

        /** @var string $morphKey */
        $morphKey = config('permission.column_names.model_morph_key');

        return $this->morphToMany(
            $roleClass,
            'model',
            $tableName,
            $morphKey,
            'role_id'
        )
            ->using(TemporalRoleUser::class)
            ->withPivot([
                'tenant_id',
                'valid_from',
                'valid_until',
                'auto_revoke',
                'assigned_by',
                'reason',
                'created_at',
                'updated_at',
            ])
            ->where(function (Builder $query) {
                // Only return currently active roles using shared filtering logic
                TemporalRoleUser::applyActiveFilter($query, 'model_has_roles.');
            });
    }
}
