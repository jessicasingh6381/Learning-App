<?php

namespace App\Http\Requests;

use App\Domain\AcademicSources\AcademicSourceOptions;
use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Models\AcademicSource;
use App\Rules\AcademicSourceUpload;
use App\Rules\SafeAcademicSourceUrl;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AcademicSourceRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $source = $this->route('source');

        return $source
            ? $this->user()->can('update', $source)
            : $this->user()->can('create', AcademicSource::class);
    }

    public function rules(): array
    {
        $source = $this->route('source');
        $kindRules = ['required', Rule::in(AcademicSourceOptions::KINDS)];
        if ($source) {
            $kindRules[] = Rule::in([$source->source_kind]);
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'source_kind' => $kindRules,
            'source_category' => ['required', Rule::in(AcademicSourceOptions::CATEGORIES)],
            'authority_level' => ['required', Rule::in(AcademicSourceOptions::AUTHORITY_LEVELS)],
            'education_provider_id' => ['nullable', $this->visibleExists('education_providers')],
            'school_year_id' => ['nullable', Rule::exists('school_years', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'grade_level_id' => ['nullable', Rule::exists('grade_levels', 'id')->where('is_active', true)],
            'subject_id' => $source ? ['prohibited'] : ['nullable', $this->visibleExists('subjects')],
            'version_label' => ['nullable', 'string', 'max:100'],
            'academic_year_label' => ['nullable', 'string', 'max:100'],
            'publication_date' => ['nullable', 'date_format:Y-m-d'],
            'source_url' => ['nullable', 'required_if:source_kind,url', 'prohibited_unless:source_kind,url', new SafeAcademicSourceUrl],
            'notes' => ['nullable', 'string', 'max:10000'],
            'source_file' => $source
                ? ['prohibited']
                : ['nullable', 'required_if:source_kind,upload', new AcademicSourceUpload],
            'tenant_id' => ['prohibited'],
            'review_status' => ['prohibited'],
            'processing_status' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('source_kind') === 'manual'
                    && blank($this->input('description'))
                    && blank($this->input('notes'))) {
                    $validator->errors()->add('description', 'A manual source needs a description or notes that identify it.');
                }
            },
        ];
    }
}
