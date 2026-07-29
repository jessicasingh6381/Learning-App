<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'activeTenant' => app(TenantContext::class)->tenant(),
            'activeSchoolYear' => SchoolYear::query()->where('status', 'active')->first(),
            'counts' => [
                'activeStudents' => Student::query()->where('status', 'active')->count(),
                'currentEnrollments' => StudentEnrollment::query()->where('status', 'active')->count(),
            ],
            'setup' => [
                'hasSchoolYear' => SchoolYear::query()->exists(),
                'hasStudent' => Student::query()->exists(),
                'hasEnrollment' => StudentEnrollment::query()->exists(),
            ],
            'activity' => AuditLog::query()->latest()->limit(8)->get(),
        ]);
    }
}
