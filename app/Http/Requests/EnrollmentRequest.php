<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('enrollments.manage');
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer'],
            'school_year_id' => ['required', 'integer'],
            'grade_level_id' => ['required', 'integer'],
            'enrollment_date' => ['required', 'date'],
            'completion_date' => ['nullable', 'date', 'after_or_equal:enrollment_date'],
            'status' => ['required', Rule::in(['planned', 'active', 'completed', 'withdrawn', 'cancelled'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $student = Student::query()->whereKey($this->integer('student_id'))->first();
            $schoolYear = SchoolYear::query()->whereKey($this->integer('school_year_id'))->first();
            $status = (string) $this->input('status');

            if (! $student) {
                $validator->errors()->add('student_id', 'The student is not available in the active tenant.');
            } elseif ($student->status !== 'active') {
                $validator->errors()->add('student_id', 'Only an active student may receive a new enrollment.');
            }

            if (! $schoolYear) {
                $validator->errors()->add('school_year_id', 'The school year is not available in the active tenant.');
            } elseif (! in_array($schoolYear->status, ['draft', 'active'], true)) {
                $validator->errors()->add('school_year_id', 'New enrollments require a draft or active school year.');
            }

            if (! GradeLevel::query()->whereKey($this->integer('grade_level_id'))->where('is_active', true)->exists()) {
                $validator->errors()->add('grade_level_id', 'Select a valid grade level.');
            }

            $requiresCompletionDate = in_array($status, ['completed', 'withdrawn'], true);
            if ($requiresCompletionDate && ! $this->filled('completion_date')) {
                $validator->errors()->add('completion_date', 'A completion or withdrawal date is required for this status.');
            }
            if (! $requiresCompletionDate && $this->filled('completion_date')) {
                $validator->errors()->add('completion_date', 'This status cannot have a completion or withdrawal date.');
            }

            if ($schoolYear && $this->filled('enrollment_date')) {
                $enrollmentDate = CarbonImmutable::parse($this->input('enrollment_date'));
                if ($enrollmentDate->lt($schoolYear->start_date) || $enrollmentDate->gt($schoolYear->end_date)) {
                    $validator->errors()->add('enrollment_date', 'The enrollment date must fall within the school year.');
                }
            }

            if ($schoolYear && $this->filled('completion_date')) {
                $completionDate = CarbonImmutable::parse($this->input('completion_date'));
                if ($completionDate->gt($schoolYear->end_date)) {
                    $validator->errors()->add('completion_date', 'The completion or withdrawal date must fall within the school year.');
                }
            }
        }];
    }
}
