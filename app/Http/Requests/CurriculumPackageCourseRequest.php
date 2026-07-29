<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CurriculumPackageCourseRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $package = $this->route('package');

        return $package instanceof CurriculumPackage
            && $package->status === 'draft'
            && $this->user()->can('update', $package);
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', $this->visibleExists('courses')],
            'grade_level_id' => ['nullable', Rule::exists('grade_levels', 'id')->where('is_active', true)],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'required' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $package = $this->route('package');
                $mapping = $this->route('mapping');
                $gradeContext = $this->filled('grade_level_id') ? 'grade:'.$this->integer('grade_level_id') : 'all';

                if ($package && CurriculumPackageCourse::query()
                    ->where('curriculum_package_id', $package->id)
                    ->where('course_id', $this->integer('course_id'))
                    ->where('grade_context_key', $gradeContext)
                    ->when($mapping, fn ($query) => $query->whereKeyNot($mapping->id))
                    ->exists()) {
                    $validator->errors()->add('course_id', 'That course and grade mapping already exists in this package.');
                }
            },
        ];
    }
}
