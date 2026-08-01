<?php

namespace App\Providers;

use App\Models\AcademicSource;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Policies\AcademicResourcePolicy;
use App\Policies\AcademicSourcePolicy;
use App\Policies\AcademicYearConfigurationPolicy;
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
        Gate::policy(EducationProvider::class, AcademicResourcePolicy::class);
        Gate::policy(CalendarProfile::class, AcademicResourcePolicy::class);
        Gate::policy(StandardsFramework::class, AcademicResourcePolicy::class);
        Gate::policy(Subject::class, AcademicResourcePolicy::class);
        Gate::policy(Course::class, AcademicResourcePolicy::class);
        Gate::policy(CurriculumPackage::class, AcademicResourcePolicy::class);
        Gate::policy(AcademicYearConfiguration::class, AcademicYearConfigurationPolicy::class);
        Gate::policy(AcademicSource::class, AcademicSourcePolicy::class);
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.')) {
                return app(PermissionService::class)->allows($ability) ?: null;
            }

            return null;
        });
    }
}
