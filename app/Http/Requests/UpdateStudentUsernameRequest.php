<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\StudentUsername;
use App\Support\StudentUsername as StudentUsernameSupport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccess', $this->route('student'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => StudentUsernameSupport::normalize($this->input('username'))]);
    }

    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                new StudentUsername,
                Rule::unique(User::class, 'username')->ignore($this->route('student')->user_id),
            ],
        ];
    }
}
