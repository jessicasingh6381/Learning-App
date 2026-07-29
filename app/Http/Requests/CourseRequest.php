<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Models\GradeLevel;
use App\Rules\ValidStatusTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CourseRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course
            ? $this->user()->can('update', $course)
            : $this->user()->can('courses.manage');
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', $this->visibleExists('subjects')],
            'standards_framework_id' => ['nullable', $this->visibleExists('standards_frameworks')],
            'education_provider_id' => ['nullable', $this->visibleExists('education_providers')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9._-]+$/', $this->tenantUnique('courses', 'code', $this->route('course')?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'minimum_grade_level_id' => ['nullable', Rule::exists('grade_levels', 'id')->where('is_active', true)],
            'maximum_grade_level_id' => ['nullable', Rule::exists('grade_levels', 'id')->where('is_active', true)],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'retired', 'archived']),
                new ValidStatusTransition($this->route('course')?->status, [
                    'draft' => ['active', 'archived'],
                    'active' => ['retired', 'archived'],
                    'retired' => ['active', 'archived'],
                    'archived' => [],
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $minimum = GradeLevel::query()->find($this->integer('minimum_grade_level_id'));
                $maximum = GradeLevel::query()->find($this->integer('maximum_grade_level_id'));

                if (($minimum === null) !== ($maximum === null)) {
                    $validator->errors()->add('maximum_grade_level_id', 'Select both ends of the grade range or leave both blank.');
                } elseif ($minimum && $maximum && $minimum->sort_order > $maximum->sort_order) {
                    $validator->errors()->add('maximum_grade_level_id', 'The maximum grade cannot precede the minimum grade.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
