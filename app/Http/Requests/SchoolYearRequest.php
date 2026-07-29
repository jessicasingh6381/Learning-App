<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('school-years.manage');
    }

    public function rules(): array
    {
        $schoolYear = $this->route('school_year');
        $id = $schoolYear?->id;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('school_years')->where('tenant_id', app(TenantContext::class)->tenantId())->ignore($id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['draft', 'active', 'closed', 'archived'])],
            'instructional_day_target' => ['nullable', 'integer', 'between:1,366'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $schoolYear = $this->route('school_year');
            $requestedStatus = (string) $this->input('status');

            if (! $schoolYear && ! in_array($requestedStatus, ['draft', 'active'], true)) {
                $validator->errors()->add('status', 'A new school year must begin as draft or active.');

                return;
            }

            if (! $schoolYear) {
                return;
            }

            $transitions = [
                'draft' => ['draft', 'active', 'archived'],
                'active' => ['active', 'closed', 'archived'],
                'closed' => ['closed', 'archived'],
                'archived' => ['archived'],
            ];

            if (! in_array($requestedStatus, $transitions[$schoolYear->status] ?? [], true)) {
                $validator->errors()->add('status', "A {$schoolYear->status} school year cannot transition to {$requestedStatus}.");
            }
        }];
    }
}
