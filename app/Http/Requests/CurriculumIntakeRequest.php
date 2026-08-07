<?php

namespace App\Http\Requests;

use App\Domain\AcademicSources\AcademicSourceOptions;
use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Models\AcademicSource;
use App\Models\EducationProvider;
use App\Models\StudentEnrollment;
use App\Rules\AcademicSourceUpload;
use App\Rules\SafeAcademicSourceUrl;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CurriculumIntakeRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        return $this->user()->can('create', AcademicSource::class);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $subjectSpecific = $this->routeIs('workspace.curriculum-intake.subject.store');

        return [
            'student_id' => $subjectSpecific ? ['prohibited'] : ['required', 'integer', Rule::exists('students', 'id')->where('tenant_id', $tenantId)->where('status', 'active')],
            'school_year_id' => $subjectSpecific ? ['prohibited'] : ['required', 'integer', Rule::exists('school_years', 'id')->where('tenant_id', $tenantId)],
            'source_origin' => $subjectSpecific ? ['prohibited'] : ['required', Rule::in(['provider', 'publisher', 'custom', 'other'])],
            'education_provider_id' => $subjectSpecific ? ['prohibited'] : ['nullable', 'required_if:source_origin,provider,publisher', 'prohibited_unless:source_origin,provider,publisher', $this->visibleExists('education_providers')],
            'subject_id' => $subjectSpecific ? ['prohibited'] : ['required', 'integer', $this->visibleExists('subjects')],
            'title' => ['required', 'string', 'max:255'],
            'source_kind' => ['required', Rule::in(AcademicSourceOptions::KINDS)],
            'source_url' => ['nullable', 'required_if:source_kind,url', 'prohibited_unless:source_kind,url', new SafeAcademicSourceUrl],
            'source_file' => ['nullable', 'required_if:source_kind,upload', 'prohibited_unless:source_kind,upload', new AcademicSourceUpload, 'mimes:pdf'],
            'manual_reference' => ['nullable', 'prohibited_unless:source_kind,manual', 'string', 'max:10000'],
            'version_label' => ['nullable', 'string', 'max:100'],
            'tenant_id' => ['prohibited'],
            'grade_level_id' => ['prohibited'],
            'review_status' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->routeIs('workspace.curriculum-intake.subject.store')) {
                    if ($this->input('source_kind') === 'manual' && blank($this->input('manual_reference'))) {
                        $validator->errors()->add('manual_reference', 'Describe the manual curriculum reference.');
                    }

                    return;
                }

                $enrollment = StudentEnrollment::query()
                    ->where('student_id', $this->integer('student_id'))
                    ->where('school_year_id', $this->integer('school_year_id'))
                    ->whereIn('status', ['planned', 'active'])
                    ->with('schoolYear.academicConfiguration')
                    ->first();

                if (! $enrollment) {
                    $validator->errors()->add('student_id', 'Choose a student enrolled in the selected school year.');
                }

                if ($this->input('source_kind') === 'manual' && blank($this->input('manual_reference'))) {
                    $validator->errors()->add('manual_reference', 'Describe the manual curriculum reference.');
                }

                $provider = $this->integer('education_provider_id')
                    ? EducationProvider::query()->find($this->integer('education_provider_id'))
                    : null;
                if ($provider && $this->input('source_origin') === 'publisher' && $provider->provider_type !== 'curriculum_publisher') {
                    $validator->errors()->add('education_provider_id', 'Choose a curriculum publisher.');
                }
                if ($provider && $this->input('source_origin') === 'provider' && $provider->provider_type === 'curriculum_publisher') {
                    $validator->errors()->add('education_provider_id', 'Choose a district or education provider.');
                }
                $configuredProviderId = $enrollment?->schoolYear?->academicConfiguration?->education_provider_id;
                if ($configuredProviderId && $configuredProviderId !== $this->integer('education_provider_id')) {
                    $validator->errors()->add('education_provider_id', 'Use the education provider configured for this school year.');
                }
            },
        ];
    }
}
