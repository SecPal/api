<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
// Models used in organizational scope methods
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * User model with UUID primary key.
 *
 * Uses UUID for primary key (defined in initial migration).
 * All foreign key references use UUID type for consistency.
 *
 * @property string $id UUID primary key
 * @property string $name
 * @property string $email
 * @property string $password
 * @property ?\Illuminate\Support\Carbon $email_verified_at
 * @property string|null $remember_token
 * @property string|null $preferred_locale
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Collection<int, UserInternalOrganizationalScope> $organizationalScopes
 * @property-read Collection<int, OrganizationalUnit> $scopedOrganizationalUnits
 * @property-read Employee|null $employee
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

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
        'preferred_locale',
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

    /**
     * Check if user has a permission assigned directly (not via roles).
     *
     * This method queries the model_has_permissions pivot table directly
     * to bypass Spatie's role-based permission resolution.
     *
     * @param  string|\Spatie\Permission\Contracts\Permission  $permission
     */
    public function hasDirectPermission($permission): bool
    {
        if (is_string($permission)) {
            // Use Spatie's Permission model directly to avoid PHPStan complexity
            $permission = \Spatie\Permission\Models\Permission::findByName($permission, $this->getDefaultGuardName());
        }

        if (! $permission instanceof \Spatie\Permission\Contracts\Permission) {
            return false;
        }

        // Query pivot table directly to check for direct assignment
        return \Illuminate\Support\Facades\DB::table('model_has_permissions')
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('permission_id', $permission->id)
            ->exists();
    }

    /**
     * Get all organizational scopes assigned to this user.
     *
     * @return HasMany<UserInternalOrganizationalScope, $this>
     */
    public function organizationalScopes(): HasMany
    {
        return $this->hasMany(UserInternalOrganizationalScope::class, 'user_id');
    }

    /**
     * Get all organizational units this user has direct scope access to.
     *
     * @return BelongsToMany<OrganizationalUnit, $this>
     */
    public function scopedOrganizationalUnits(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationalUnit::class,
            'user_internal_organizational_scopes',
            'user_id',
            'organizational_unit_id'
        )->withPivot(['access_level', 'include_descendants']);
    }

    /**
     * Get all organizational units accessible to this user.
     *
     * Includes directly scoped units and their descendants (when include_descendants is true).
     * Uses optimized queries to avoid N+1 issues.
     *
     * @return Collection<int, OrganizationalUnit>
     */
    public function getAccessibleOrganizationalUnits(): Collection
    {
        $scopes = $this->organizationalScopes()->get();

        /** @var SupportCollection<int, string> $directUnitIds */
        $directUnitIds = collect();
        /** @var SupportCollection<int, string> $ancestorIdsForDescendants */
        $ancestorIdsForDescendants = collect();

        foreach ($scopes as $scope) {
            // Always include the directly scoped unit
            $directUnitIds->push($scope->organizational_unit_id);

            // Collect ancestor IDs for descendant query
            if ($scope->include_descendants) {
                $ancestorIdsForDescendants->push($scope->organizational_unit_id);
            }
        }

        // Single query for all descendants (N+1 fix)
        /** @var SupportCollection<int, string> $descendantIds */
        $descendantIds = collect();
        if ($ancestorIdsForDescendants->isNotEmpty()) {
            $descendantIds = OrganizationalUnitClosure::whereIn('ancestor_id', $ancestorIdsForDescendants->unique())
                ->where('depth', '>', 0)
                ->pluck('descendant_id');
        }

        /** @var SupportCollection<int, string> $accessibleUnitIds */
        $accessibleUnitIds = $directUnitIds->merge($descendantIds)->unique();

        return OrganizationalUnit::whereIn('id', $accessibleUnitIds)->get();
    }

    /**
     * Get the employee associated with this user account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<Employee, $this>
     */
    public function employee(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    /**
     * Check if user has access to a specific organizational unit.
     *
     * Uses optimized queries to avoid N+1 issues:
     * - Pre-fetches all ancestor relationships for scopes with include_descendants
     * - Single query to check if target unit is a descendant of any scoped unit
     *
     * @param  string|null  $minimumLevel  Optional minimum access level required
     */
    public function hasAccessToUnit(OrganizationalUnit $unit, ?string $minimumLevel = null): bool
    {
        $scopes = $this->organizationalScopes()->get();

        if ($scopes->isEmpty()) {
            return false;
        }

        // Collect unit IDs for direct scope check and descendant check
        /** @var SupportCollection<int, string> $directScopeUnitIds */
        $directScopeUnitIds = collect();
        /** @var SupportCollection<int, string> $descendantScopeUnitIds */
        $descendantScopeUnitIds = collect();

        foreach ($scopes as $scope) {
            $directScopeUnitIds->push($scope->organizational_unit_id);
            if ($scope->include_descendants) {
                $descendantScopeUnitIds->push($scope->organizational_unit_id);
            }
        }

        // Check if unit is directly scoped
        $isDirectlyScoped = $directScopeUnitIds->contains($unit->id);

        // Check if unit is a descendant of any scoped unit (single query)
        $ancestorScopeId = null;
        if (! $isDirectlyScoped && $descendantScopeUnitIds->isNotEmpty()) {
            $ancestorScopeId = OrganizationalUnitClosure::whereIn('ancestor_id', $descendantScopeUnitIds->unique())
                ->where('descendant_id', $unit->id)
                ->where('depth', '>', 0)
                ->value('ancestor_id');
        }

        // Determine which scope applies
        $applicableScope = null;
        if ($isDirectlyScoped) {
            // Find scope with direct match
            $applicableScope = $scopes->first(fn ($s) => $s->organizational_unit_id === $unit->id);
        } elseif ($ancestorScopeId !== null) {
            // Find scope for the ancestor
            $applicableScope = $scopes->first(fn ($s) => $s->organizational_unit_id === $ancestorScopeId && $s->include_descendants);
        }

        if ($applicableScope === null) {
            return false;
        }

        // If no minimum level specified, any access is sufficient
        if ($minimumLevel === null) {
            return true;
        }

        // Check if access level is sufficient
        return $applicableScope->hasMinimumAccessLevel($minimumLevel);
    }
}
