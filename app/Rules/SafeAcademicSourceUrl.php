<?php

namespace App\Rules;

use App\Support\SafeExternalUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeAcademicSourceUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (SafeExternalUrl::inspect($value) === null) {
            $fail('Enter a valid external web address without embedded credentials.');
        }
    }
}
