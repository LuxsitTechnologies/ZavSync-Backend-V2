<?php

namespace Tests\Concerns;

trait BuildsLegacyInvoiceSnapshots
{
    /** @return array<string, mixed> */
    protected function legacySnapshot(int $invoiceCount = 1, string $sourceCompanyId = '4'): array
    {
        $invoices = [];
        $items = [];
        $submissions = [];
        $submissionCount = $invoiceCount === 128 ? 121 : $invoiceCount;

        for ($index = 1; $index <= $invoiceCount; $index++) {
            $subtotal = in_array($index, [1, 2], true) && $invoiceCount === 128 ? '101.00' : '100.00';
            $tax = $index === 3 && $invoiceCount === 128 ? '19.00' : '18.00';
            $total = in_array($index, [4, 5], true) && $invoiceCount === 128 ? '119.00' : '118.00';
            $invoices[] = [
                'id' => $index, 'company_id' => $sourceCompanyId, 'buyer_registration_no' => '1234567', 'buyer_name' => "Historical Buyer {$index}",
                'buyer_type' => 'Registered', 'fbr_invoice_type' => 'Sale Invoice', 'sale_origination_province' => 'Sindh',
                'destination_of_supply' => 'Sindh', 'fbr_sale_type' => 'Standardized Goods', 'invoice_number' => (string) (900000 + $index),
                'issue_date' => '2026-06-17', 'due_date' => '2026-07-17', 'subtotal' => $subtotal, 'total_tax' => $tax,
                'total_discount' => '0.00', 'total_amount' => $total, 'amount_paid' => '0.00', 'status' => ($invoiceCount === 128 ? ($index <= 42 || in_array($index, [51, 61, 62], true)) : $index === 1) ? 'draft' : 'sent',
                'tax_rate' => '18.00', 'notes' => null, 'created_at' => '2026-06-17 10:00:00', 'updated_at' => '2026-06-17 10:00:00',
            ];
            $items[] = [
                'id' => $index, 'invoice_id' => $index, 'service_id' => null, 'hs_code' => '9983.0000', 'description' => 'Historical service',
                'quantity' => '1.000', 'rate' => '100.00', 'fbr_rate_id' => '18', 'uom' => 'Unit', 'value_excl_st' => '100.00',
                'sales_tax' => '18.00', 'extra_tax' => '0.00', 'further_tax' => '0.00', 'st_withheld' => '0.00',
                'sro_schedule_id' => null, 'sro_item_id' => null, 'amount' => '118.00', 'created_at' => '2026-06-17 10:00:00', 'updated_at' => '2026-06-17 10:00:00',
            ];
            if ($invoiceCount !== 128 || $index < 36 || $index > 42) {
                $success = $invoiceCount !== 128 || $index <= 122;
                $ambiguousSubmissionIds = [51 => 42, 61 => 52, 62 => 53];
                $ambiguous = $invoiceCount === 128 && array_key_exists($index, $ambiguousSubmissionIds);
                $submissionId = $ambiguousSubmissionIds[$index] ?? (in_array($index, [42, 52, 53], true) && $invoiceCount === 128 ? 10000 + $index : $index);
                $submissions[] = [
                    'id' => $submissionId, 'invoice_id' => $index, 'status' => $success ? 'success' : 'failed',
                    'fbr_invoice_number' => $success && ! $ambiguous ? "FBR-{$index}" : null,
                    'api_response' => ['status' => $success ? 'success' : 'failed', 'message' => 'Sanitized fixture', 'authorization' => 'must-not-persist', 'token' => 'must-not-persist'],
                    'retry_count' => 0, 'last_attempt_at' => '2026-06-17 10:01:00', 'created_at' => '2026-06-17 10:01:00', 'updated_at' => '2026-06-17 10:01:00',
                ];
            }
        }

        return [
            'source_system' => 'zavsync_v1',
            'manifest' => [
                'counts' => ['invoices' => $invoiceCount, 'invoice_items' => $invoiceCount, 'fbr_invoice_submissions' => $submissionCount],
                'invoice_statuses' => $invoiceCount === 128 ? ['draft' => 45, 'sent' => 83] : ['draft' => 1, 'sent' => 0],
                'fbr_statuses' => $invoiceCount === 128 ? ['success' => 115, 'failed' => 6] : ['success' => $submissionCount, 'failed' => 0],
                'mismatch_counts' => $invoiceCount === 128 ? ['subtotal' => 2, 'tax' => 1, 'total' => 2] : ['subtotal' => 0, 'tax' => 0, 'total' => 0],
            ],
            'invoices' => $invoices,
            'invoice_items' => $items,
            'fbr_invoice_submissions' => $submissions,
        ];
    }
}
