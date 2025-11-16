<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Models\Permission;
use App\Models\Person;
use App\Models\Secret;
use App\Models\SecretAttachment;
use App\Models\SecretShare;
use App\Observers\PersonObserver;
use App\Observers\SecretObserver;
use App\Policies\PermissionManagementPolicy;
use App\Policies\RoleManagementPolicy;
use App\Policies\SecretAttachmentPolicy;
use App\Policies\SecretPolicy;
use App\Policies\SecretSharePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Person::observe(PersonObserver::class);
        Secret::observe(SecretObserver::class);

        // Register policy for Spatie Role model
        Gate::policy(Role::class, RoleManagementPolicy::class);

        // Register policy for Spatie Permission model
        Gate::policy(Permission::class, PermissionManagementPolicy::class);

        // Register policy for Secret model
        Gate::policy(Secret::class, SecretPolicy::class);

        // Register policy for SecretShare model
        Gate::policy(SecretShare::class, SecretSharePolicy::class);

        // Register policy for SecretAttachment model
        Gate::policy(SecretAttachment::class, SecretAttachmentPolicy::class);

        // Register gates for user permission management
        $this->registerUserPermissionGates();
    }

    /**
     * Register authorization gates for direct user permission management.
     */
    private function registerUserPermissionGates(): void
    {
        $policy = new \App\Policies\UserPermissionPolicy;

        Gate::define('viewPermissions', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->viewPermissions($currentUser, $targetUser);
        });

        Gate::define('assignPermission', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->assignPermission($currentUser, $targetUser);
        });

        Gate::define('revokePermission', function ($currentUser, $targetUser) use ($policy) {
            assert($currentUser instanceof \App\Models\User);
            assert($targetUser instanceof \App\Models\User);

            return $policy->revokePermission($currentUser, $targetUser);
        });
    }
}
