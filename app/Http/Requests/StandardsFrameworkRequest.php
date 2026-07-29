<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Rules\ValidStatusTransition;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StandardsFrameworkRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $framework = $this->route('framework');

        return $framework
            ? $this->user()->can('update', $framework)
            : $this->user()->can('standards.manage');
    }

    public function rules(): array
    {
        $unique = Rule::unique('standards_frameworks', 'name')
            ->where(fn (Builder $query) => $query
                ->where('ownership_key', 'tenant:'.app(TenantContext::class)->tenantId())
                ->where('version_label', (string) $this->input('version_label', 'unversioned')))
            ->ignore($this->route('framework')?->id);

        return [
            'education_provider_id' => ['nullable', $this->visibleExists('education_providers')],
            'name' => ['required', 'string', 'max:255', $unique],
            'short_name' => ['nullable', 'string', 'max:60'],
            'jurisdiction' => ['nullable', 'string', 'max:100'],
            'version_label' => ['required', 'string', 'max:100'],
            'effective_start_date' => ['nullable', 'date_format:Y-m-d'],
            'effective_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_start_date'],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'retired', 'archived']),
                new ValidStatusTransition($this->route('framework')?->status, [
                    'draft' => ['active', 'archived'],
                    'active' => ['retired', 'archived'],
                    'retired' => ['active', 'archived'],
                    'archived' => [],
                ]),
            ],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
