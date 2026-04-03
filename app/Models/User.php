<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
// Models used in organizational scope methods
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Laragear\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Laragear\TwoFactor\TwoFactorAuthentication;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;
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
 * @property int $tenant_id Foreign key to tenant_keys
 * @property ?\Illuminate\Support\Carbon $email_verified_at
 * @property string|null $remember_token
 * @property string|null $preferred_locale
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read TenantKey $tenant
 * @property-read Collection<int, UserInternalOrganizationalScope> $organizationalScopes
 * @property-read Collection<int, OrganizationalUnit> $scopedOrganizationalUnits
 * @property-read Employee|null $employee
 * @property-read \Laragear\TwoFactor\Models\TwoFactorAuthentication $twoFactorAuth
 */
class User extends Authenticatable implements TwoFactorAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use EnforcesTenantRouteBinding, HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, TwoFactorAuthentication {
        EnforcesTenantRouteBinding::resolveRouteBindingQuery insteadof HasUuids;
        HasUuids::resolveRouteBindingQuery as resolveUuidRouteBindingQuery;
    }

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
        'tenant_id',
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
     * Get the tenant this user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<TenantKey, $this>
     */
    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'tenant_id');
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

        $teamId = $this->resolvePermissionsTeamId();

        $relation = $this->morphToMany(
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

        $relation->wherePivot('tenant_id', $teamId);

        return $relation;
    }

    /**
     * Check if user has a permission assigned directly (not via roles).
     *
     * This method queries the model_has_permissions pivot table directly
     * to bypass Spatie's role-based permission resolution.
     *
     * @param  string|PermissionContract  $permission
     */
    public function hasDirectPermission($permission): bool
    {
        if (is_string($permission)) {
            // Use Spatie's Permission model directly to avoid PHPStan complexity
            $permission = SpatiePermission::findByName($permission, $this->getDefaultGuardName());
        }

        if (! $permission instanceof PermissionContract) {
            return false;
        }

        $teamId = $this->resolvePermissionsTeamId();
        $now = now();

        // Query pivot table directly to check for direct assignment
        $query = DB::table('model_has_permissions')
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('permission_id', $permission->id)
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $now);
            })
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>', $now);
            });

        $query->where('tenant_id', $teamId);

        return $query->exists();
    }

    private function resolvePermissionsTeamId(): int
    {
        $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        if (is_int($teamId)) {
            return $teamId;
        }

        return $this->tenant_id;
    }

    /**
     * Determine whether this user belongs to the same tenant as another user.
     */
    public function sharesTenantWith(self $user): bool
    {
        return $this->tenant_id === $user->tenant_id;
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
     * Determine whether the user has a prepared but not yet confirmed MFA enrollment.
     */
    public function hasPendingTwoFactorEnrollment(): bool
    {
        return $this->twoFactorAuth->exists && $this->twoFactorAuth->isDisabled();
    }

    /**
     * Return the number of unused recovery codes currently available.
     */
    public function getRemainingTwoFactorRecoveryCodesCount(): int
    {
        return (int) $this->getRecoveryCodes()
            ->where('used_at', null)
            ->count();
    }

    /**
     * Return when the current recovery-code batch was generated.
     */
    public function getTwoFactorRecoveryCodesGeneratedAt(): ?DateTimeInterface
    {
        return $this->twoFactorAuth->recovery_codes_generated_at;
    }

    /**
     * Get all customer assignments for this user.
     *
     * @return HasMany<CustomerAssignment, $this>
     */
    public function customerAssignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class, 'user_id');
    }

    /**
     * Get all site assignments for this user.
     *
     * @return HasMany<SiteAssignment, $this>
     */
    public function siteAssignments(): HasMany
    {
        return $this->hasMany(SiteAssignment::class, 'user_id');
    }

    /**
     * Get all customers assigned to this user.
     *
     * Many-to-many relationship through customer_assignments table.
     *
     * @return BelongsToMany<Customer, $this>
     */
    public function assignedCustomers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_assignments', 'user_id', 'customer_id')
            ->withPivot(['role', 'valid_from', 'valid_until', 'notes'])
            ->withTimestamps();
    }

    /**
     * Get all sites assigned to this user.
     *
     * Many-to-many relationship through site_assignments table.
     *
     * @return BelongsToMany<Site, $this>
     */
    public function assignedSites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_assignments', 'user_id', 'site_id')
            ->withPivot(['role', 'valid_from', 'valid_until', 'notes'])
            ->withTimestamps();
    }

    /**
     * Get all organizational unit IDs accessible to this user.
     *
     * Returns array of UUIDs for use in whereIn() queries.
     *
     * @return array<int, string>
     */
    public function getAccessibleOrganizationalUnitIds(): array
    {
        /** @var array<int, string> */
        return $this->getAccessibleOrganizationalUnits()->pluck('id')->toArray();
    }

    /**
     * Determine whether the user may open the customer collection via scoped access.
     *
     * This intentionally checks access entitlements, not whether matching records
     * currently exist. Users with active assignments or organizational scopes
     * may therefore receive a filtered empty collection instead of a 403.
     */
    public function hasAccessibleCustomers(): bool
    {
        return $this->customerAssignments()
            ->where('tenant_id', $this->tenant_id)
            ->currentlyActive()
            ->exists()
            || $this->siteAssignments()
                ->where('tenant_id', $this->tenant_id)
                ->currentlyActive()
                ->exists()
            || $this->organizationalScopes()
                ->whereHas('organizationalUnit', fn ($q) => $q->where('tenant_id', $this->tenant_id))
                ->exists();
    }

    /**
     * Get all customers the user can access.
     *
     * Access is granted through:
     * - Direct customer assignments
     * - Access to sites belonging to the customer (via organizational unit or site assignment)
     *
     * @return Collection<int, Customer>
     */
    public function getAccessibleCustomers(): Collection
    {
        return $this->accessibleCustomersQuery()->get();
    }

    /**
     * Determine whether the user may open the site collection via scoped access.
     *
     * This intentionally checks access entitlements, not whether matching records
     * currently exist. Users with active assignments or organizational scopes
     * may therefore receive a filtered empty collection instead of a 403.
     */
    public function hasAccessibleSites(): bool
    {
        return $this->siteAssignments()
            ->where('tenant_id', $this->tenant_id)
            ->currentlyActive()
            ->exists()
            || $this->customerAssignments()
                ->where('tenant_id', $this->tenant_id)
                ->currentlyActive()
                ->exists()
            || $this->organizationalScopes()
                ->whereHas('organizationalUnit', fn ($q) => $q->where('tenant_id', $this->tenant_id))
                ->exists();
    }

    /**
     * Build the base query for all customers the user can access.
     *
     * Access is granted through:
     * - Direct customer assignments
     * - Access to sites belonging to the customer (via organizational unit or site assignment)
     *
     * @return Builder<Customer>
     */
    private function accessibleCustomersQuery(): Builder
    {
        $accessibleUnitIds = $this->getAccessibleOrganizationalUnitIds();
        $assignedSiteIds = $this->siteAssignments()->currentlyActive()->pluck('site_id')->toArray();
        $assignedCustomerIds = $this->customerAssignments()->currentlyActive()->pluck('customer_id')->toArray();

        return Customer::query()
            ->where('tenant_id', $this->tenant_id)
            ->where(function ($query) use ($assignedCustomerIds, $accessibleUnitIds, $assignedSiteIds) {
                // Direct customer assignment
                $query->whereIn('id', $assignedCustomerIds)
                    // Or has sites in accessible org units
                    ->orWhereHas('sites', function ($siteQuery) use ($accessibleUnitIds, $assignedSiteIds) {
                        $siteQuery->where(function ($sq) use ($accessibleUnitIds, $assignedSiteIds) {
                            $sq->whereIn('organizational_unit_id', $accessibleUnitIds)
                                ->orWhereIn('id', $assignedSiteIds);
                        });
                    });
            });
    }

    /**
     * Get all sites the user can access.
     *
     * Access is granted through:
     * - Direct site assignments
     * - Access to site's organizational unit
     * - Assignment to site's customer (Key Accounts see all customer sites)
     *
     * @return Collection<int, Site>
     */
    public function getAccessibleSites(): Collection
    {
        return $this->accessibleSitesQuery()->get();
    }

    /**
     * Build the base query for all sites visible to this user.
     *
     * Users with the sites.read permission can view all tenant sites.
     * Users without that permission are restricted to their scoped site access.
     *
     * @return Builder<Site>
     */
    public function visibleSitesQuery(): Builder
    {
        if ($this->can('sites.read')) {
            return Site::query()->where('tenant_id', $this->tenant_id);
        }

        return $this->accessibleSitesQuery();
    }

    /**
     * Build the base query for all sites the user can access.
     *
     * Access is granted through:
     * - Direct site assignments
     * - Access to site's organizational unit
     * - Assignment to site's customer (Key Accounts see all customer sites)
     *
     * @return Builder<Site>
     */
    private function accessibleSitesQuery(): Builder
    {
        $accessibleUnitIds = $this->getAccessibleOrganizationalUnitIds();
        $assignedSiteIds = $this->siteAssignments()->currentlyActive()->pluck('site_id')->toArray();
        $assignedCustomerIds = $this->customerAssignments()->currentlyActive()->pluck('customer_id')->toArray();

        return Site::query()
            ->where('tenant_id', $this->tenant_id)
            ->where(function ($query) use ($accessibleUnitIds, $assignedSiteIds, $assignedCustomerIds) {
                $query->whereIn('organizational_unit_id', $accessibleUnitIds)
                    ->orWhereIn('id', $assignedSiteIds)
                    ->orWhereIn('customer_id', $assignedCustomerIds);
            });
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
