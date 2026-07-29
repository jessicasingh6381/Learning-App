<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Models\Student;
use App\Models\TenantMembership;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Student::class);

        return Inertia::render('Students/Index', ['students' => Student::query()->orderBy('last_name')->orderBy('first_name')->get()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Student::class);

        return Inertia::render('Students/Form');
    }

    public function store(StudentRequest $request, AuditService $audit): RedirectResponse
    {
        $student = DB::transaction(function () use ($request, $audit) {
            $student = Student::create($request->validated());
            $audit->record('student.created', $student, [], $student->toArray());

            return $student;
        });

        return redirect()->route('students.show', $student)->with('success', 'Student added.');
    }

    public function show(Student $student): Response
    {
        Gate::authorize('view', $student);

        $access = null;
        if ($student->user_id !== null) {
            $user = $student->user()->firstOrFail();
            $membership = TenantMembership::query()
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $user->id)
                ->first();
            $access = [
                'username' => $user->username,
                'status' => $membership?->status === 'active' && $membership->role === 'student'
                    ? 'active'
                    : 'disabled',
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ];
        }

        return Inertia::render('Students/Show', [
            'student' => $student->load('enrollments.schoolYear', 'enrollments.gradeLevel'),
            'access' => $access,
        ]);
    }

    public function edit(Student $student): Response
    {
        Gate::authorize('update', $student);

        return Inertia::render('Students/Form', ['student' => $student]);
    }

    public function update(StudentRequest $request, Student $student, AuditService $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $student, $audit) {
            $before = $student->toArray();
            $student->update($request->validated());
            $audit->record('student.updated', $student, $before, $student->fresh()->toArray());
        });

        return redirect()->route('students.show', $student)->with('success', 'Student updated.');
    }

    public function archive(Student $student, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $student);
        DB::transaction(function () use ($student, $audit) {
            $before = $student->toArray();
            $student->update(['status' => 'archived', 'archived_at' => now()]);
            $audit->record('student.archived', $student, $before, $student->fresh()->toArray());
        });

        return back()->with('success', 'Student archived; enrollment history was retained.');
    }
}
