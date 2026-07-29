<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Student;
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
            if (! Student::query()->whereKey($this->integer('student_id'))->exists()) {
                $validator->errors()->add('student_id', 'The student is not available in the active tenant.');
            }
            if (! SchoolYear::query()->whereKey($this->integer('school_year_id'))->exists()) {
                $validator->errors()->add('school_year_id', 'The school year is not available in the active tenant.');
            }
            if (! GradeLevel::query()->whereKey($this->integer('grade_level_id'))->where('is_active', true)->exists()) {
                $validator->errors()->add('grade_level_id', 'Select a valid grade level.');
            }
        }];
    }
}
