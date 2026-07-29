<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Rules\ValidStatusTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EducationProviderRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $provider = $this->route('provider');

        return $provider
            ? $this->user()->can('update', $provider)
            : $this->user()->can('providers.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->tenantUnique('education_providers', 'name', $this->route('provider')?->id)],
            'short_name' => ['nullable', 'string', 'max:60'],
            'provider_type' => ['required', Rule::in(['district', 'state_agency', 'private_school', 'homeschool_program', 'curriculum_publisher', 'learning_coop', 'custom'])],
            'state_or_region' => ['nullable', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
            'website_url' => ['nullable', 'url', 'max:2048'],
            'status' => [
                'required',
                Rule::in(['active', 'retired', 'archived']),
                new ValidStatusTransition($this->route('provider')?->status, [
                    'active' => ['retired', 'archived'],
                    'retired' => ['active', 'archived'],
                    'archived' => [],
                ]),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['country_code' => strtoupper((string) $this->input('country_code', 'US'))]);
    }
}
