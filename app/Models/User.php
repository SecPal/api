<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Models\Concerns\EnforcesTenantRouteBinding;
use DateTimeInterface;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
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
use Laravel\Sanctum\NewAccessToken;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
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
class User extends Authenticatable implements MustVerifyEmailContract, TwoFactorAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use EnforcesTenantRouteBinding, HasApiTokens, HasFactory, HasRoles, HasUuids, MustVerifyEmail, Notifiable, TwoFactorAuthentication {
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

    public const API_ACCESS_ABILITY = 'api-access';

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
     * @return HasMany<PasskeyCredential, $this>
     */
    public function passkeyCredentials(): HasMany
    {
        return $this->hasMany(PasskeyCredential::class);
    }

    /**
     * Issue a personal access token with the default SecPal API ability set.
     */
    public function issueApiToken(string $name): NewAccessToken
    {
        return $this->createToken($name, [self::API_ACCESS_ABILITY]);
    }

    /**
     * Override Spatie's roles() relationship to use custom temporal pivot.
     *
     * This enables time-limited role assignments with automatic expiration.
     * Only currently active roles are returned by default.
     *
     * @return MorphToMany<SpatieRole, $this, TemporalRoleUser>
     */
    public function roles(): MorphToMany
    {
        /** @var class-string<SpatieRole> $roleClass */
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

    /**
     * Determine if the model has (one of) the given role(s).
     *
     * When no explicit permissions team is set, Spatie's eager-loading path builds the
     * roles relation on a blank model instance, which loses this user's tenant fallback.
     * Querying through the concrete model instance preserves the intended tenant scoping.
     *
     * @param  mixed  $roles
     */
    public function hasRole($roles, ?string $guard = null): bool
    {
        $resolvedRoles = $this->resolveRolesForCurrentContext();
        $filteredRoles = $this->filterRolesByGuard($resolvedRoles, $guard);

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        if ($roles instanceof \BackedEnum) {
            $roles = $roles->value;
        }

        if (is_int($roles)) {
            $requestedRoleId = (string) $roles;

            return $filteredRoles->contains(
                fn (SpatieRole $role): bool => $this->roleKeyMatches($role, $requestedRoleId)
            );
        }

        if (is_string($roles)) {
            if (PermissionRegistrar::isUid($roles)) {
                $requestedRoleId = $roles;

                return $filteredRoles->contains(
                    fn (SpatieRole $role): bool => $this->roleKeyMatches($role, $requestedRoleId)
                );
            }

            return $filteredRoles->contains(
                fn (SpatieRole $role): bool => $role->name === $roles
            );
        }

        if ($roles instanceof SpatieRole) {
            $requestedRoleId = $this->normalizeRoleKey($roles->getKey());

            if ($requestedRoleId === null) {
                return false;
            }

            return $filteredRoles->contains(
                fn (SpatieRole $role): bool => $this->roleKeyMatches($role, $requestedRoleId)
            );
        }

        if (is_array($roles)) {
            foreach ($roles as $role) {
                if ($this->hasRole($role, $guard)) {
                    return true;
                }
            }

            return false;
        }

        if ($roles instanceof SupportCollection) {
            foreach ($roles as $role) {
                if ($this->hasRole($role, $guard)) {
                    return true;
                }
            }

            return false;
        }

        throw new \TypeError('Unsupported type for $roles parameter to hasRole().');
    }

    /**
     * Determine if the model has all of the given role(s).
     *
     * @param  mixed  $roles
     */
    public function hasAllRoles($roles, ?string $guard = null): bool
    {
        $roles = $this->normalizeEnumValue($roles);

        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        foreach ($this->normalizeRolesToList($roles) as $role) {
            if (! $this->hasRole($role, $guard)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the model has exactly all of the given role(s).
     *
     * @param  mixed  $roles
     */
    public function hasExactRoles($roles, ?string $guard = null): bool
    {
        if (is_string($roles) && str_contains($roles, '|')) {
            $roles = $this->convertPipeToArray($roles);
        }

        $rolesList = $this->normalizeRolesToList($roles);
        $currentCount = $this->resolveRoleNamesForCurrentContext($guard)->count();

        return count($rolesList) === $currentCount && $this->hasAllRoles($rolesList, $guard);
    }

    /**
     * @return SupportCollection<int, string>
     */
    public function getRoleNames(): SupportCollection
    {
        return $this->resolveRoleNamesForCurrentContext();
    }

    // tenant_id is annotated as int but can be null on transient model instances
    // (e.g. partially constructed or factory-created users in tests that have not
    // completed full DB setup).  Returning null lets callers use IS NULL semantics
    // which matches no real rows, preserving deny-by-default without a TypeError.
    /** @phpstan-ignore return.unusedType */
    private function resolvePermissionsTeamId(): ?int
    {
        $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        if ($teamId !== null) {
            return is_int($teamId) ? $teamId : (int) $teamId;
        }

        return $this->tenant_id;
    }

    /**
     * Resolve the active roles collection for the current permission context.
     *
     * @return SupportCollection<int, SpatieRole>
     */
    private function resolveRolesForCurrentContext(): SupportCollection
    {
        if (app(PermissionRegistrar::class)->getPermissionsTeamId() === null) {
            if (! $this->relationLoaded('roles')) {
                $this->setRelation('roles', $this->roles()->get());
            }

            /** @var SupportCollection<int, SpatieRole> $roles */
            $roles = $this->roles;

            return $roles;
        }

        $this->loadMissing('roles');

        /** @var SupportCollection<int, SpatieRole> $roles */
        $roles = $this->roles;

        return $roles;
    }

    /**
     * @param  SupportCollection<int, SpatieRole>  $roles
     * @return SupportCollection<int, SpatieRole>
     */
    private function filterRolesByGuard(SupportCollection $roles, ?string $guard): SupportCollection
    {
        if ($guard === null) {
            return $roles;
        }

        /** @var SupportCollection<int, SpatieRole> $filtered */
        $filtered = $roles->filter(
            fn (SpatieRole $role): bool => $role->guard_name === $guard
        )->values();

        return $filtered;
    }

    /**
     * @return SupportCollection<int, string>
     */
    private function resolveRoleNamesForCurrentContext(?string $guard = null): SupportCollection
    {
        /** @var SupportCollection<int, string> $roleNames */
        $roleNames = $this->filterRolesByGuard($this->resolveRolesForCurrentContext(), $guard)
            ->map(fn (SpatieRole $role): string => $role->name)
            ->values();

        return $roleNames;
    }

    private function normalizeEnumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    /**
     * @param  mixed  $roles
     * @return list<int|string|SpatieRole>
     */
    private function normalizeRolesToList($roles): array
    {
        $roles = $this->normalizeEnumValue($roles);

        if ($roles instanceof SupportCollection) {
            $items = [];

            foreach ($roles as $role) {
                $normalizedRole = $this->normalizeEnumValue($role);

                if (is_int($normalizedRole) || is_string($normalizedRole) || $normalizedRole instanceof SpatieRole) {
                    $items[] = $normalizedRole;
                }
            }

            return $items;
        }

        if (is_array($roles)) {
            $items = [];

            foreach (array_values($roles) as $role) {
                $normalizedRole = $this->normalizeEnumValue($role);

                if (is_int($normalizedRole) || is_string($normalizedRole) || $normalizedRole instanceof SpatieRole) {
                    $items[] = $normalizedRole;
                }
            }

            return $items;
        }

        if (is_int($roles) || is_string($roles) || $roles instanceof SpatieRole) {
            return [$roles];
        }

        return [];
    }

    private function normalizeRoleKey(mixed $roleKey): ?string
    {
        if (is_int($roleKey) || is_string($roleKey)) {
            return (string) $roleKey;
        }

        return null;
    }

    private function roleKeyMatches(SpatieRole $role, string $requestedRoleId): bool
    {
        $resolvedRoleId = $this->normalizeRoleKey($role->getKey());

        return $resolvedRoleId !== null && $resolvedRoleId === $requestedRoleId;
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
        $scopes = $this->organizationalScopes;

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
     * Resolve the scopes that apply to a specific organizational unit.
     *
     * Direct scopes take precedence over inherited descendant scopes for the
     * same unit. When no direct scope exists, all ancestor scopes with
     * include_descendants=true are returned.
     *
     * @return SupportCollection<int, UserInternalOrganizationalScope>
     */
    public function getApplicableOrganizationalScopesForUnit(OrganizationalUnit $unit): SupportCollection
    {
        $scopes = $this->organizationalScopes;

        if ($scopes->isEmpty()) {
            /** @var SupportCollection<int, UserInternalOrganizationalScope> $emptyScopes */
            $emptyScopes = collect();

            return $emptyScopes;
        }

        /** @var SupportCollection<int, UserInternalOrganizationalScope> $directScopes */
        $directScopes = $scopes
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->organizational_unit_id === $unit->id)
            ->values();

        if ($directScopes->isNotEmpty()) {
            return $directScopes;
        }

        /** @var SupportCollection<int, string> $ancestorScopeUnitIds */
        $ancestorScopeUnitIds = $scopes
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->include_descendants)
            ->pluck('organizational_unit_id')
            ->unique()
            ->values();

        if ($ancestorScopeUnitIds->isEmpty()) {
            /** @var SupportCollection<int, UserInternalOrganizationalScope> $emptyScopes */
            $emptyScopes = collect();

            return $emptyScopes;
        }

        /** @var SupportCollection<int, string> $matchingAncestorIds */
        $matchingAncestorIds = OrganizationalUnitClosure::query()
            ->where('descendant_id', $unit->id)
            ->where('depth', '>', 0)
            ->whereIn('ancestor_id', $ancestorScopeUnitIds)
            ->pluck('ancestor_id');

        if ($matchingAncestorIds->isEmpty()) {
            /** @var SupportCollection<int, UserInternalOrganizationalScope> $emptyScopes */
            $emptyScopes = collect();

            return $emptyScopes;
        }

        /** @var SupportCollection<int, UserInternalOrganizationalScope> $inheritedScopes */
        $inheritedScopes = $scopes
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->include_descendants
                && $matchingAncestorIds->contains($scope->organizational_unit_id))
            ->values();

        return $inheritedScopes;
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
        $scopes = $this->getApplicableOrganizationalScopesForUnit($unit);

        if ($scopes->isEmpty()) {
            return false;
        }

        // If no minimum level specified, any access is sufficient
        if ($minimumLevel === null) {
            return true;
        }

        return $scopes->contains(
            fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel($minimumLevel)
        );
    }
}
