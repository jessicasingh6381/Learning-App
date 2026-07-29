<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Rules\ValidStatusTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        $subject = $this->route('subject');

        return $subject
            ? $this->user()->can('update', $subject)
            : $this->user()->can('subjects.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', $this->tenantUnique('subjects', 'code', $this->route('subject')?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'status' => [
                'required',
                Rule::in(['active', 'retired']),
                new ValidStatusTransition($this->route('subject')?->status, [
                    'active' => ['retired'],
                    'retired' => ['active'],
                ]),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
