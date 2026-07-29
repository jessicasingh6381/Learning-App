<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('dashboard.view');
        $canViewActivity = Gate::allows('tenant.manage');
        $activeSchoolYear = SchoolYear::query()->where('status', 'active')->first();
        $academicConfiguration = $activeSchoolYear?->academicConfiguration()
            ->with('curriculumPackage.courseMappings')->first();

        return Inertia::render('Dashboard', [
            'activeTenant' => app(TenantContext::class)->tenant(),
            'activeSchoolYear' => $activeSchoolYear,
            'counts' => [
                'activeStudents' => Student::query()->where('status', 'active')->count(),
                'currentEnrollments' => StudentEnrollment::query()->where('status', 'active')->count(),
            ],
            'setup' => [
                'hasSchoolYear' => SchoolYear::query()->exists(),
                'hasStudent' => Student::query()->exists(),
                'hasEnrollment' => StudentEnrollment::query()->exists(),
            ],
            'academicSetup' => [
                'status' => $academicConfiguration?->status ?? 'not_started',
                'completed' => collect([
                    $academicConfiguration?->education_provider_id,
                    $academicConfiguration?->calendar_profile_id,
                    $academicConfiguration?->standards_framework_id,
                    $academicConfiguration?->curriculum_package_id,
                    $academicConfiguration?->curriculumPackage?->courseMappings->isNotEmpty() ? true : null,
                ])->filter()->count(),
                'total' => 5,
            ],
            'activity' => $canViewActivity ? AuditLog::query()->latest()->limit(8)->get() : [],
            'canViewActivity' => $canViewActivity,
        ]);
    }
}
