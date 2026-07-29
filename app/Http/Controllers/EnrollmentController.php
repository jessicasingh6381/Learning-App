<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentRequest;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', StudentEnrollment::class);

        $schoolYears = SchoolYear::query()
            ->whereIn('status', ['draft', 'active'])
            ->orderByDesc('start_date')
            ->get()
            ->map(static fn (SchoolYear $schoolYear): array => [
                'id' => $schoolYear->id,
                'name' => $schoolYear->name,
                'start_date' => $schoolYear->start_date->format('Y-m-d'),
                'end_date' => $schoolYear->end_date->format('Y-m-d'),
            ]);
        $hasOldInput = $request->session()->hasOldInput();

        return Inertia::render('Enrollments/Form', [
            'students' => Student::query()->where('status', 'active')->orderBy('last_name')->get(),
            'schoolYears' => $schoolYears,
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'oldInput' => $hasOldInput ? [
                'student_id' => $request->old('student_id') !== null
                    ? (int) $request->old('student_id')
                    : null,
                'school_year_id' => $request->old('school_year_id') !== null
                    ? (int) $request->old('school_year_id')
                    : null,
                'grade_level_id' => $request->old('grade_level_id') !== null
                    ? (int) $request->old('grade_level_id')
                    : null,
                'enrollment_date' => $request->old('enrollment_date'),
                'completion_date' => $request->old('completion_date'),
                'status' => $request->old('status'),
            ] : null,
        ]);
    }

    public function store(EnrollmentRequest $request, AuditService $audit): RedirectResponse
    {
        $enrollment = DB::transaction(function () use ($request, $audit) {
            $data = $request->validated();
            Student::query()->whereKey($data['student_id'])->lockForUpdate()->firstOrFail();

            if (in_array($data['status'], ['planned', 'active'], true)) {
                $duplicate = StudentEnrollment::query()->where('student_id', $data['student_id'])
                    ->where('school_year_id', $data['school_year_id'])->whereIn('status', ['planned', 'active'])
                    ->lockForUpdate()->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['student_id' => 'This student already has a planned or active enrollment for that school year.']);
                }
            }

            $enrollment = StudentEnrollment::create($data);
            $audit->record('enrollment.created', $enrollment, [], $enrollment->toArray());

            return $enrollment;
        });

        return redirect()->route('students.show', $enrollment->student_id)->with('success', 'Enrollment added.');
    }
}
