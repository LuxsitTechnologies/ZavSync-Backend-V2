<?php

namespace App\Services\Banking;

use Illuminate\Validation\ValidationException;

class IntegerMoneyParser
{
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public function parse(mixed $value, string $field = 'amount'): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) && $value !== null) {
            throw ValidationException::withMessages([$field => 'The monetary amount must be supplied as an integer minor-unit value or decimal string.']);
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            throw ValidationException::withMessages([$field => 'A monetary amount is required.']);
        }
        $negative = str_starts_with($raw, '(') && str_ends_with($raw, ')');
        if ($negative) {
            $raw = substr($raw, 1, -1);
        }
        $raw = str_replace([',', ' ', "\u{00A0}"], '', $raw);
        if (str_starts_with($raw, '-')) {
            $negative = true;
            $raw = substr($raw, 1);
        } elseif (str_starts_with($raw, '+')) {
            $raw = substr($raw, 1);
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
            throw ValidationException::withMessages([$field => 'The monetary amount must contain at most two decimal places.']);
        }
        [$major, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $majorValue = (int) $major;
        if ((string) $majorValue !== ltrim($major, '0') && ! preg_match('/^0+$/', $major)) {
            throw ValidationException::withMessages([$field => 'The monetary amount exceeds the supported range.']);
        }
        if ($majorValue > intdiv(self::MAX_SAFE_INTEGER, 100)) {
            throw ValidationException::withMessages([$field => 'The monetary amount exceeds the supported range.']);
        }
        $minor = ($majorValue * 100) + (int) str_pad($fraction, 2, '0');
        if ($minor > self::MAX_SAFE_INTEGER) {
            throw ValidationException::withMessages([$field => 'The monetary amount exceeds the supported range.']);
        }

        return $negative ? -$minor : $minor;
    }
}
