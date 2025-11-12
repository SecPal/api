<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Providers;

use App\Models\Permission;
use App\Models\Person;
use App\Observers\PersonObserver;
use App\Policies\PermissionManagementPolicy;
use App\Policies\RoleManagementPolicy;
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

        // Register policy for Spatie Role model
        Gate::policy(Role::class, RoleManagementPolicy::class);

        // Register policy for Spatie Permission model
        Gate::policy(Permission::class, PermissionManagementPolicy::class);
    }
}
