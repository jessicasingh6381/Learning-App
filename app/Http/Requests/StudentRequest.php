<?php

namespace App\Http\Requests;

use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('students.manage');
    }

    public function rules(): array
    {
        $student = $this->route('student');
        $statuses = $student?->status === 'archived'
            ? ['archived']
            : ['active', 'inactive'];

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'status' => ['sometimes', Rule::in($statuses)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('user_id') && ! TenantMembership::query()
                ->where('tenant_id', app(TenantContext::class)->tenantId())
                ->where('user_id', $this->integer('user_id'))->where('status', 'active')->exists()) {
                $validator->errors()->add('user_id', 'A linked user must be an active member of this tenant.');
            }
        }];
    }
}
