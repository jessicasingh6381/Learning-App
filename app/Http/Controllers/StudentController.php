<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Models\Student;
use App\Models\TenantMembership;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Student::class);
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status')
            : 'active';
        $tenantId = app(TenantContext::class)->tenant()->id;
        $students = Student::query()
            ->with([
                'user.memberships' => fn ($query) => $query->where('tenant_id', $tenantId),
                'enrollments' => fn ($query) => $query->with(['schoolYear', 'gradeLevel']),
            ])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderBy('last_name')->orderBy('first_name')->get()
            ->map(function (Student $student): array {
                $enrollment = $student->enrollments->first(fn ($item) => in_array($item->status, ['planned', 'active'], true));
                $membership = $student->user?->memberships->first();

                return [
                    'id' => $student->id,
                    'name' => $student->display_name,
                    'status' => $student->status,
                    'enrollment' => $enrollment ? [
                        'grade' => $enrollment->gradeLevel->name,
                        'school_year' => $enrollment->schoolYear->name,
                        'status' => $enrollment->status,
                    ] : null,
                    'access_status' => match (true) {
                        $student->user_id === null => 'Not enabled',
                        $membership?->status !== 'active' => 'Disabled',
                        $student->user?->must_change_password => 'Password change required',
                        default => 'Ready',
                    },
                ];
            });

        return Inertia::render('Students/Index', ['students' => $students, 'filter' => $status]);
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

        $student->load('enrollments.schoolYear', 'enrollments.gradeLevel');

        return Inertia::render('Students/Show', [
            'student' => [
                'id' => $student->id,
                'name' => $student->display_name,
                'status' => $student->status,
                'enrollments' => $student->enrollments->map(fn ($enrollment) => [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status,
                    'school_year' => ['name' => $enrollment->schoolYear->name],
                    'grade_level' => ['name' => $enrollment->gradeLevel->name],
                    'enrollment_date' => $enrollment->enrollment_date->format('Y-m-d'),
                    'completion_date' => $enrollment->completion_date?->format('Y-m-d'),
                ])->values(),
            ],
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
