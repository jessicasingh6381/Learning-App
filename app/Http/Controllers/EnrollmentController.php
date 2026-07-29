<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentRequest;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', StudentEnrollment::class);

        return Inertia::render('Enrollments/Form', [
            'students' => Student::query()->where('status', 'active')->orderBy('last_name')->get(),
            'schoolYears' => SchoolYear::query()->whereIn('status', ['draft', 'active'])->orderByDesc('start_date')->get(),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(),
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
