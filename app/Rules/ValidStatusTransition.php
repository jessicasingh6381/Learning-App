<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidStatusTransition implements ValidationRule
{
    /**
     * @param  array<string, array<int, string>>  $transitions
     */
    public function __construct(
        private ?string $currentStatus,
        private array $transitions,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->currentStatus === null) {
            return;
        }

        $allowed = [$this->currentStatus, ...($this->transitions[$this->currentStatus] ?? [])];

        if (! in_array($value, $allowed, true)) {
            $fail("The status cannot transition from {$this->currentStatus} to {$value}.");
        }
    }
}
