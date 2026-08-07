<?php

namespace App\Providers;

use App\Contracts\PdfTextExtractor;
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
use App\Services\CfisdGrade5MathYearAtGlanceParser;
use App\Services\CfisdGrade5ElarParentYearAtGlanceParser;
use App\Services\CfisdGrade5ScienceYearAtGlanceParser;
use App\Services\TexasTeksMultigradeSocialStudiesParser;
use App\Services\CurriculumParserRegistry;
use App\Services\DeclarativeCurriculumFormatParser;
use App\Models\CurriculumFormatProfile;
use App\Services\SmalotPdfTextExtractor;
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
        $this->app->bind(PdfTextExtractor::class, SmalotPdfTextExtractor::class);
        $this->app->bind(CurriculumParserRegistry::class, function ($app) {
            $profiles = CurriculumFormatProfile::query()->where('status', 'active')->get()
                ->map(fn ($profile) => $app->make(DeclarativeCurriculumFormatParser::class, ['profile' => $profile]));
            return new CurriculumParserRegistry([
                $app->make(CfisdGrade5MathYearAtGlanceParser::class),
                $app->make(CfisdGrade5ElarParentYearAtGlanceParser::class),
                $app->make(CfisdGrade5ScienceYearAtGlanceParser::class),
                $app->make(TexasTeksMultigradeSocialStudiesParser::class),
                ...$profiles,
            ]);
        });
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
