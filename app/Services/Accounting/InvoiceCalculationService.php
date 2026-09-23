<?php

namespace App\Services\Accounting;

use Illuminate\Validation\ValidationException;

class InvoiceCalculationService
{
    private const MAX_MONEY = 9007199254740991;

    /**
     * @param  array<int, array<string, mixed>>  $inputLines
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function calculate(array $inputLines): array
    {
        $lines = [];
        $totals = ['subtotal' => 0, 'discount' => 0, 'taxable_amount' => 0, 'sales_tax' => 0, 'other_tax' => 0, 'advance_tax' => 0, 'withholding_tax' => 0, 'total' => 0];

        foreach ($inputLines as $index => $input) {
            $subtotal = $this->multiplyQuantity((int) $input['unit_price'], (int) $input['quantity_milli'], $index);
            $discount = (int) ($input['discount'] ?? 0);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(["lines.$index.discount" => 'The line discount cannot exceed the line subtotal.']);
            }
            $taxable = $subtotal - $discount;
            $salesTax = $this->rateAmount($taxable, (int) ($input['tax_rate_bps'] ?? 0));
            $otherTax = $this->rateAmount($taxable, (int) ($input['other_tax_rate_bps'] ?? 0));
            $advanceTax = $this->rateAmount($taxable, (int) ($input['advance_tax_rate_bps'] ?? 0));
            $withholdingTax = $this->rateAmount($taxable, (int) ($input['withholding_tax_rate_bps'] ?? 0));
            $total = $this->checkedSum([$taxable, $salesTax, $otherTax, $advanceTax, -$withholdingTax], "lines.$index");
            if ($total < 0) {
                throw ValidationException::withMessages(["lines.$index" => 'Withholding tax cannot exceed the amount payable for the line.']);
            }
            $line = [
                'position' => $index + 1, 'item_id' => $input['item_id'] ?? null, 'item_name' => $input['item_name'] ?? null,
                'description' => $input['description'], 'quantity_milli' => (int) $input['quantity_milli'], 'unit' => $input['unit'],
                'unit_price' => (int) $input['unit_price'], 'subtotal' => $subtotal, 'discount' => $discount,
                'taxable_amount' => $taxable, 'tax_rate_bps' => (int) ($input['tax_rate_bps'] ?? 0), 'tax_amount' => $salesTax,
                'other_tax_rate_bps' => (int) ($input['other_tax_rate_bps'] ?? 0), 'other_tax_amount' => $otherTax,
                'advance_tax_rate_bps' => (int) ($input['advance_tax_rate_bps'] ?? 0), 'advance_tax_amount' => $advanceTax,
                'withholding_tax_rate_bps' => (int) ($input['withholding_tax_rate_bps'] ?? 0), 'withholding_tax_amount' => $withholdingTax,
                'total' => $total, 'sales_type' => $input['sales_type'], 'tax_metadata' => $input['tax_metadata'] ?? null,
            ];
            foreach ($totals as $key => $value) {
                $lineKey = match ($key) {
                    'sales_tax' => 'tax_amount', 'other_tax' => 'other_tax_amount', 'advance_tax' => 'advance_tax_amount',
                    'withholding_tax' => 'withholding_tax_amount', default => $key,
                };
                $totals[$key] = $this->checkedSum([$value, (int) $line[$lineKey]], 'lines');
            }
            $lines[] = $line;
        }

        return compact('lines', 'totals');
    }

    private function multiplyQuantity(int $unitPrice, int $quantityMilli, int $index): int
    {
        $wholeQuantity = intdiv($quantityMilli, 1000);
        $fraction = $quantityMilli % 1000;
        if ($wholeQuantity > 0 && $unitPrice > intdiv(self::MAX_MONEY, $wholeQuantity)) {
            throw ValidationException::withMessages(["lines.$index.unit_price" => 'The calculated line subtotal exceeds the supported minor-unit range.']);
        }
        $whole = $unitPrice * $wholeQuantity;
        $fractional = intdiv(($unitPrice * $fraction) + 500, 1000);

        return $this->checkedSum([$whole, $fractional], "lines.$index.unit_price");
    }

    private function rateAmount(int $amount, int $basisPoints): int
    {
        $whole = intdiv($amount, 10000) * $basisPoints;
        $remainder = intdiv((($amount % 10000) * $basisPoints) + 5000, 10000);

        return $whole + $remainder;
    }

    /** @param array<int, int> $values */
    private function checkedSum(array $values, string $field): int
    {
        $sum = 0;
        foreach ($values as $value) {
            if (($value > 0 && $sum > self::MAX_MONEY - $value) || ($value < 0 && $sum < -self::MAX_MONEY - $value)) {
                throw ValidationException::withMessages([$field => 'The calculated amount exceeds the supported minor-unit range.']);
            }

            $sum += $value;
        }

        return $sum;
    }
}
