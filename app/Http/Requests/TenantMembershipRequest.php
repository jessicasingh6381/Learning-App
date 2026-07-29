<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TenantMembershipRequest extends FormRequest
{
    private const ROLES = ['owner', 'administrator', 'teacher', 'parent', 'tutor', 'student'];

    private const ROLE_RANK = [
        'student' => 0,
        'parent' => 1,
        'tutor' => 1,
        'teacher' => 2,
        'administrator' => 3,
        'owner' => 4,
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('membership'));
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(self::ROLES)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $target = $this->route('membership');
            $actor = app(TenantContext::class)->membership();
            $requestedRole = (string) $this->input('role');

            if ($target->user()->first()?->student()->exists()) {
                $validator->errors()->add('role', 'Manage linked student accounts from the student access screen.');

                return;
            }

            if (($target->role === 'owner' || $requestedRole === 'owner') && $actor->role !== 'owner') {
                $validator->errors()->add('role', 'Only a tenant owner may add, remove, or modify an owner role.');
            }

            if ($target->id === $actor->id
                && (self::ROLE_RANK[$requestedRole] ?? -1) > (self::ROLE_RANK[$target->role] ?? -1)) {
                $validator->errors()->add('role', 'Members cannot promote their own role.');
            }
        }];
    }
}
