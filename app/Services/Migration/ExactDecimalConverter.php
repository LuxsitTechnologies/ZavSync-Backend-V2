<?php

namespace App\Services\Migration;

use InvalidArgumentException;

class ExactDecimalConverter
{
    /** @param array<int, int> $values */
    public function sum(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if (($value > 0 && $total > PHP_INT_MAX - $value) || ($value < 0 && $total < PHP_INT_MIN - $value)) {
                throw new InvalidArgumentException('The reconciliation total exceeds the supported integer range.');
            }
            $total += $value;
        }

        return $total;
    }

    public function money(mixed $value, bool $allowNegative = false): int
    {
        return $this->scaledInteger($value, 2, $allowNegative);
    }

    public function quantity(mixed $value): int
    {
        return $this->scaledInteger($value, 3, false);
    }

    public function percentageToBasisPoints(mixed $value): int
    {
        return $this->scaledInteger($value, 2, false);
    }

    public function scaledInteger(mixed $value, int $scale, bool $allowNegative): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('Decimal values must be supplied as strings or integers; floating-point input is not supported.');
        }

        $decimal = trim((string) $value);
        if (! preg_match('/^(?<sign>-?)(?<whole>0|[1-9]\d*)(?:\.(?<fraction>\d+))?$/', $decimal, $matches)) {
            throw new InvalidArgumentException('The decimal value has an invalid format.');
        }
        if ($matches['sign'] === '-' && ! $allowNegative) {
            throw new InvalidArgumentException('Negative values are not supported for this field.');
        }

        $fraction = $matches['fraction'] ?? '';
        if (mb_strlen($fraction) > $scale) {
            throw new InvalidArgumentException("The decimal value exceeds the supported {$scale}-place precision.");
        }

        $digits = ltrim($matches['whole'].str_pad($fraction, $scale, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string) PHP_INT_MAX;
        if (mb_strlen($digits) > mb_strlen($maximum) || (mb_strlen($digits) === mb_strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('The decimal value exceeds the supported integer range.');
        }

        $integer = (int) $digits;

        return $matches['sign'] === '-' ? -$integer : $integer;
    }
}
