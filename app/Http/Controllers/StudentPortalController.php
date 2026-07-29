<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentPortalController extends Controller
{
    public function home(Request $request): Response
    {
        return Inertia::render('StudentPortal/Home', $this->portalProps($request));
    }

    public function learning(Request $request): Response
    {
        return Inertia::render('StudentPortal/Learning', $this->portalProps($request));
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('StudentPortal/Profile', $this->portalProps($request));
    }

    private function portalProps(Request $request): array
    {
        $student = Student::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $enrollment = $student->enrollments()
            ->with(['schoolYear:id,name', 'gradeLevel:id,name'])
            ->where('status', 'active')
            ->first();

        return [
            'student' => [
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'preferred_name' => $student->preferred_name,
                'display_name' => $student->display_name,
            ],
            'academy' => app(TenantContext::class)->tenant()->name,
            'username' => $request->user()->username,
            'enrollment' => $enrollment ? [
                'school_year' => $enrollment->schoolYear->name,
                'grade_level' => $enrollment->gradeLevel->name,
                'status' => $enrollment->status,
            ] : null,
        ];
    }
}
