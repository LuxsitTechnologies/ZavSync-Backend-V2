<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PakistanCnic implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = is_string($value) ? preg_replace('/\D+/', '', $value) : null;
        if ($digits === null || ! preg_match('/^\d{13}$/', $digits)) {
            $fail('The :attribute must contain exactly 13 digits.');
        }
    }
}
