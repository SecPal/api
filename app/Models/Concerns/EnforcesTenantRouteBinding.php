<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models\Concerns;

use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Fail closed on route-bound tenant-owned models.
 *
 * When an authenticated user is present, bindings are constrained to that
 * user's tenant. As a fallback for tenant-prefixed routes, the explicit
 * {tenant} route parameter is used. Models that intentionally expose global
 * records must opt in explicitly via routeBindingAllowsGlobalRecords(); in the
 * current API surface this is limited to system qualifications and onboarding
 * form templates.
 */
trait EnforcesTenantRouteBinding
{
    /**
     * Scope route model binding to the current tenant.
     *
     * @param  Builder<static>|Relation<static, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder<static>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        /** @var Builder<static> $baseQuery */
        $baseQuery = $query instanceof Relation ? $query->getQuery() : $query;

        $resolvedQuery = $this->resolveBaseRouteBindingQuery($baseQuery, $value, $field);

        $tenantId = $this->resolveCurrentRouteTenantId();

        if ($tenantId === null) {
            return $resolvedQuery;
        }

        return $this->applyTenantRouteBindingConstraint($resolvedQuery, $tenantId);
    }

    /**
     * Resolve the base binding query before tenant constraints are applied.
     *
     * @param  Builder<static>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder<static>
     */
    protected function resolveBaseRouteBindingQuery($query, $value, $field = null): Builder
    {
        $field ??= $this->getRouteKeyName();

        if ($this->usesUuidRouteBinding($field) && ! Str::isUuid($value)) {
            $invalidRouteKey = match (true) {
                is_int($value), is_string($value) => $value,
                is_float($value), is_bool($value), $value === null => var_export($value, true),
                default => get_debug_type($value),
            };

            throw (new ModelNotFoundException)->setModel(static::class, $invalidRouteKey);
        }

        return $query->where($field, $value);
    }

    /**
     * Determine whether the current route binding key is UUID-backed.
     */
    protected function usesUuidRouteBinding(string $field): bool
    {
        return in_array(HasUuids::class, class_uses_recursive($this), true)
            && in_array($field, $this->uniqueIds(), true);
    }

    /**
     * Apply the tenant constraint used for route binding.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function applyTenantRouteBindingConstraint(Builder $query, int $tenantId): Builder
    {
        $tenantColumn = $this->qualifyColumn($this->routeBindingTenantColumn());

        if (! $this->routeBindingAllowsGlobalRecords()) {
            return $query->where($tenantColumn, $tenantId);
        }

        return $query->where(function (Builder $tenantQuery) use ($tenantColumn, $tenantId): void {
            $tenantQuery->where($tenantColumn, $tenantId)
                ->orWhereNull($tenantColumn);
        });
    }

    /**
     * Get the tenant column used for direct route binding constraints.
     */
    protected function routeBindingTenantColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * Determine whether route binding should include global records.
     */
    protected function routeBindingAllowsGlobalRecords(): bool
    {
        return false;
    }

    /**
     * Resolve the tenant context used for route binding.
     */
    protected function resolveCurrentRouteTenantId(): ?int
    {
        /** @var User|null $authUser */
        $authUser = Auth::user();

        if ($authUser instanceof User && $authUser->tenant_id !== null) {
            return $authUser->tenant_id;
        }

        $routeTenant = request()->route('tenant');

        if ($routeTenant instanceof TenantKey) {
            return $routeTenant->id;
        }

        if (is_numeric($routeTenant)) {
            return (int) $routeTenant;
        }

        return null;
    }
}
