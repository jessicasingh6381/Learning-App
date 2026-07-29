<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Rules\ValidStatusTransition;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurriculumPackageRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $package = $this->route('package');

        return $package
            ? $this->user()->can('update', $package)
            : $this->user()->can('curriculum.manage');
    }

    public function rules(): array
    {
        $unique = Rule::unique('curriculum_packages', 'name')
            ->where(fn (Builder $query) => $query
                ->where('ownership_key', 'tenant:'.app(TenantContext::class)->tenantId())
                ->where('version_label', (string) $this->input('version_label')))
            ->ignore($this->route('package')?->id);

        return [
            'education_provider_id' => ['nullable', $this->visibleExists('education_providers')],
            'standards_framework_id' => ['nullable', $this->visibleExists('standards_frameworks')],
            'name' => ['required', 'string', 'max:255', $unique],
            'version_label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'retired', 'archived']),
                new ValidStatusTransition($this->route('package')?->status, [
                    'draft' => ['active', 'archived'],
                    'active' => ['retired', 'archived'],
                    'retired' => ['active', 'archived'],
                    'archived' => [],
                ]),
            ],
            'effective_start_date' => ['nullable', 'date_format:Y-m-d'],
            'effective_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_start_date'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
