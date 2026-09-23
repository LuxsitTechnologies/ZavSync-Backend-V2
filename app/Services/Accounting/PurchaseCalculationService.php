<?php

namespace App\Services\Accounting;

use Illuminate\Validation\ValidationException;

class PurchaseCalculationService
{
    private const MAX_MONEY = 9007199254740991;

    /**
     * @param  array<int, array<string, mixed>>  $inputLines
     * @return array{lines:array<int,array<string,mixed>>,totals:array<string,int>}
     */
    public function calculate(array $inputLines, bool $includeWithholding = false): array
    {
        $lines = [];
        $totals = ['subtotal' => 0, 'discount' => 0, 'taxable_amount' => 0, 'tax' => 0, 'withholding_tax' => 0, 'gross_total' => 0, 'total' => 0];
        foreach ($inputLines as $index => $input) {
            $subtotal = $this->multiplyQuantity((int) $input['unit_price'], (int) $input['quantity_milli'], $index);
            $discount = (int) ($input['discount'] ?? 0);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(["lines.$index.discount" => 'The line discount cannot exceed the line subtotal.']);
            }
            $taxable = $subtotal - $discount;
            $tax = $this->rateAmount($taxable, (int) ($input['tax_rate_bps'] ?? 0));
            $withholding = $includeWithholding ? $this->rateAmount($taxable, (int) ($input['withholding_rate_bps'] ?? 0)) : 0;
            $gross = $this->checkedSum([$taxable, $tax], "lines.$index");
            $total = $this->checkedSum([$gross, -$withholding], "lines.$index");
            if ($total < 0) {
                throw ValidationException::withMessages(["lines.$index.withholding_rate_bps" => 'Withholding cannot exceed the gross line amount.']);
            }
            $line = [
                'position' => $index + 1, 'item_id' => $input['item_id'] ?? null, 'item_name' => $input['item_name'] ?? null,
                'description' => $input['description'], 'procurement_type' => $input['procurement_type'],
                'quantity_milli' => (int) $input['quantity_milli'], 'unit' => $input['unit'], 'unit_price' => (int) $input['unit_price'],
                'subtotal' => $subtotal, 'discount' => $discount, 'taxable_amount' => $taxable,
                'tax_rate_bps' => (int) ($input['tax_rate_bps'] ?? 0), 'tax_amount' => $tax,
                'total' => $total, 'expense_account_id' => $input['expense_account_id'] ?? null, 'metadata' => $input['metadata'] ?? null,
            ];
            if ($includeWithholding) {
                $line['withholding_rate_bps'] = (int) ($input['withholding_rate_bps'] ?? 0);
                $line['withholding_amount'] = $withholding;
                $line['purchase_order_line_id'] = $input['purchase_order_line_id'] ?? null;
                $line['purchase_receipt_line_id'] = $input['purchase_receipt_line_id'] ?? null;
            }
            foreach (['subtotal', 'discount', 'taxable_amount', 'total'] as $key) {
                $totals[$key] = $this->checkedSum([$totals[$key], (int) $line[$key]], 'lines');
            }
            $totals['tax'] = $this->checkedSum([$totals['tax'], $tax], 'lines');
            $totals['withholding_tax'] = $this->checkedSum([$totals['withholding_tax'], $withholding], 'lines');
            $totals['gross_total'] = $this->checkedSum([$totals['gross_total'], $gross], 'lines');
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

        return $this->checkedSum([$unitPrice * $wholeQuantity, intdiv(($unitPrice * $fraction) + 500, 1000)], "lines.$index.unit_price");
    }

    private function rateAmount(int $amount, int $basisPoints): int
    {
        return (intdiv($amount, 10000) * $basisPoints) + intdiv((($amount % 10000) * $basisPoints) + 5000, 10000);
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
