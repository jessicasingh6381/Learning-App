<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('school-years.manage');
    }

    public function rules(): array
    {
        $id = $this->route('schoolYear')?->id;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('school_years')->where('tenant_id', app(TenantContext::class)->tenantId())->ignore($id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['draft', 'active', 'closed', 'archived'])],
            'instructional_day_target' => ['nullable', 'integer', 'between:1,366'],
        ];
    }
}
