<?php

namespace App\Providers;

use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.')) {
                return app(PermissionService::class)->allows($ability) ?: null;
            }

            return null;
        });
    }
}
