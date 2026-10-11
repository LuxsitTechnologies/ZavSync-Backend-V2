<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class CustomerPhone implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\+?[0-9\s\-().]+$/', $value)) {
            $fail('The :attribute may only contain digits and common phone separators.');

            return;
        }

        $digitCount = strlen((string) preg_replace('/\D/', '', $value));
        if ($digitCount < 7 || $digitCount > 15) {
            $fail('The :attribute must contain between 7 and 15 digits.');
        }
    }
}
