<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Rules\SafeAcademicSourceUrl;
use App\Rules\ValidStatusTransition;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarProfileRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $calendar = $this->route('calendar');

        return $calendar
            ? $this->user()->can('update', $calendar)
            : $this->user()->can('calendars.manage');
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $unique = Rule::unique('calendar_profiles', 'name')
            ->where(fn (Builder $query) => $query
                ->where('ownership_key', 'tenant:'.$tenantId)
                ->where('academic_year_label', (string) $this->input('academic_year_label', '')))
            ->ignore($this->route('calendar')?->id);

        return [
            'education_provider_id' => ['nullable', $this->visibleExists('education_providers')],
            'name' => ['required', 'string', 'max:255', $unique],
            'academic_year_label' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'timezone' => ['required', 'timezone:all'],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'retired']),
                new ValidStatusTransition($this->route('calendar')?->status, [
                    'draft' => ['active'],
                    'active' => ['retired'],
                    'retired' => ['active'],
                    'archived' => [],
                ]),
            ],
            'source_type' => ['required', Rule::in(['provider', 'tenant_custom', 'imported', 'manual'])],
            'source_url' => ['nullable', new SafeAcademicSourceUrl],
            'source_version' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['academic_year_label' => (string) $this->input('academic_year_label', '')]);
    }
}
