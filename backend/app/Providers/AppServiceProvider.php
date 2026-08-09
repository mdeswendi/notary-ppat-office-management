<?php

namespace App\Providers;

use App\Policies\RolePolicy;
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
        // Registered explicitly because Laravel's policy discovery maps
        // App\Models\* to App\Policies\*, and the Role model belongs to
        // spatie/laravel-permission rather than to us. The package model is
        // kept as-is (D-045) instead of being subclassed just to make
        // auto-discovery work.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
