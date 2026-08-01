<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeAcademicSourceUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail('Enter a valid external web address.');

            return;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['https', 'http'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            $fail('Enter a valid external web address without embedded credentials.');

            return;
        }

        $blockedName = $host === 'localhost'
            || ! str_contains($host, '.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
        $blockedIp = filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if ($blockedName || $blockedIp) {
            $fail('Enter a publicly reachable external web address.');
        }
    }
}
