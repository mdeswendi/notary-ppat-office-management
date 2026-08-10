<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
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
        // Registered explicitly because Laravel's policy discovery maps
        // App\Models\* to App\Policies\*, and the Role model belongs to
        // spatie/laravel-permission rather than to us. The package model is
        // kept as-is (D-045) instead of being subclassed just to make
        // auto-discovery work.
        Gate::policy(Role::class, RolePolicy::class);

        // Registered explicitly for symmetry with the line above. Laravel would
        // discover App\Models\User -> App\Policies\UserPolicy on its own, but
        // finding both policies in one place beats knowing which of two
        // mechanisms applies to which model.
        Gate::policy(User::class, UserPolicy::class);

        // The authorization configuration itself: the permission catalogue,
        // role grants, and role membership all authorize through this.
        Gate::policy(Permission::class, PermissionPolicy::class);
    }
}
