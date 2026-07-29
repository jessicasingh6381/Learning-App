<?php

namespace App\Rules;

use App\Support\StudentUsername as StudentUsernameSupport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StudentUsername implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $username = StudentUsernameSupport::normalize(is_string($value) ? $value : null);

        if (! preg_match('/\A[a-z0-9._-]{3,40}\z/', $username)) {
            $fail('The username must be 3 to 40 characters using only lowercase letters, numbers, periods, hyphens, or underscores.');

            return;
        }

        if (StudentUsernameSupport::isReserved($username)) {
            $fail('That username is reserved. Choose another username.');
        }
    }
}
