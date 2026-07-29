<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnableStudentAccessRequest;
use App\Http\Requests\ResetStudentPasswordRequest;
use App\Http\Requests\UpdateStudentUsernameRequest;
use App\Models\Student;
use App\Models\TenantMembership;
use App\Services\AuditService;
use App\Services\StudentAccessService;
use App\Support\StudentUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudentAccessController extends Controller
{
    public function show(Student $student): Response
    {
        Gate::authorize('manageAccess', $student);

        return Inertia::render('Students/Access', [
            'student' => [
                'id' => $student->id,
                'name' => trim($student->first_name.' '.$student->last_name),
                'display_name' => $student->display_name,
                'status' => $student->status,
            ],
            'access' => $this->accessDetails($student),
            'suggestedUsername' => $student->user_id === null
                ? StudentUsername::suggest($student->preferred_name ?: $student->first_name, $student->last_name)
                : null,
        ]);
    }

    public function enable(
        EnableStudentAccessRequest $request,
        Student $student,
        StudentAccessService $access,
        AuditService $audit,
    ): RedirectResponse {
        $access->enable($student, $request->validated(), $audit);

        return redirect()->route('students.access.show', $student)
            ->with('success', 'Student portal access enabled.');
    }

    public function updateUsername(
        UpdateStudentUsernameRequest $request,
        Student $student,
        StudentAccessService $access,
        AuditService $audit,
    ): RedirectResponse {
        $access->updateUsername($student, $request->validated('username'), $audit);

        return back()->with('success', 'Student username updated.');
    }

    public function resetPassword(
        ResetStudentPasswordRequest $request,
        Student $student,
        StudentAccessService $access,
        AuditService $audit,
    ): RedirectResponse {
        $access->resetPassword($student, $request->validated('password'), $audit);

        return back()->with('success', 'Temporary password set. A password change is required at next login.');
    }

    public function disable(
        Request $request,
        Student $student,
        StudentAccessService $access,
        AuditService $audit,
    ): RedirectResponse {
        Gate::authorize('manageAccess', $student);
        $request->validate(['confirm' => ['accepted']]);
        $access->disable($student, $audit);

        return back()->with('success', 'Student portal access disabled.');
    }

    public function reenable(
        Student $student,
        StudentAccessService $access,
        AuditService $audit,
    ): RedirectResponse {
        Gate::authorize('manageAccess', $student);
        $access->reenable($student, $audit);

        return back()->with('success', 'Student portal access re-enabled.');
    }

    private function accessDetails(Student $student): ?array
    {
        if ($student->user_id === null) {
            return null;
        }

        $user = $student->user()->firstOrFail();
        $membership = TenantMembership::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'username' => $user->username,
            'status' => $membership?->status === 'active' && $membership->role === 'student'
                ? 'active'
                : 'disabled',
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'enabled_at' => $student->student_access_enabled_at?->toIso8601String(),
        ];
    }
}
