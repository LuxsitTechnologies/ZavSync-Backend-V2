<?php

namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;

class IntegerAllocationService
{
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public function proportional(int $total, int $part, int $whole): int
    {
        if ($total < 0 || $part < 0 || $whole <= 0 || $part > $whole) {
            throw ValidationException::withMessages(['quantity_milli' => 'The requested integer allocation is invalid.']);
        }

        $base = intdiv($total, $whole) * $part;
        $remainderProduct = ($total % $whole) * $part;
        $result = $base + intdiv($remainderProduct + intdiv($whole, 2), $whole);
        if ($result > self::MAX_SAFE_INTEGER) {
            throw ValidationException::withMessages(['amount' => 'The calculated minor-unit value exceeds the supported range.']);
        }

        return $result;
    }

    public function valueFromUnitCost(int $unitCost, int $quantityMilli): int
    {
        if ($unitCost < 0 || $quantityMilli < 0 || $unitCost > 9000000000 || $quantityMilli > 1000000000) {
            throw ValidationException::withMessages(['amount' => 'Unit cost or quantity exceeds the supported integer range.']);
        }
        $product = $unitCost * $quantityMilli;
        $result = intdiv($product + 500, 1000);
        if ($result > self::MAX_SAFE_INTEGER) {
            throw ValidationException::withMessages(['amount' => 'The calculated minor-unit value exceeds the supported range.']);
        }

        return $result;
    }

    public function unitCost(int $value, int $quantityMilli): int
    {
        if ($quantityMilli === 0) {
            return 0;
        }
        $result = intdiv(($value * 1000) + intdiv($quantityMilli, 2), $quantityMilli);
        if ($result > self::MAX_SAFE_INTEGER) {
            throw ValidationException::withMessages(['amount' => 'The calculated unit cost exceeds the supported range.']);
        }

        return $result;
    }
}
