<?php

namespace App\Services\Fbr;

use App\Models\FbrCompanyConfiguration;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;

class PakistanFbrPayloadMapper
{
    /** @return array<string, mixed> */
    public function map(PakistanFbrInvoice $invoice, FbrCompanyConfiguration $configuration): array
    {
        if ($configuration->company_id !== $invoice->company_id) {
            throw new \LogicException('FBR configuration must belong to the document company.');
        }

        return [
            'invoiceType' => $invoice->invoice_type,
            'invoiceDate' => $invoice->invoice_date->format('Y-m-d'),
            'invoiceRefNo' => $invoice->invoice_number,
            'sellerNTNCNIC' => $configuration->seller_tax_identifier,
            'sellerBusinessName' => $configuration->seller_business_name,
            'sellerProvince' => $invoice->origin_province,
            'sellerAddress' => $configuration->seller_address,
            'buyerNTNCNIC' => $invoice->buyer_snapshot['registration_number'] ?? null,
            'buyerBusinessName' => $invoice->buyer_snapshot['name'],
            'buyerProvince' => $invoice->destination_province,
            'buyerAddress' => $invoice->buyer_snapshot['address'] ?? null,
            'buyerRegistrationType' => $invoice->buyer_snapshot['type'],
            'scenarioId' => $invoice->scenarioId(),
            'items' => $invoice->lines->map(fn (PakistanFbrInvoiceLine $line): array => [
                'hsCode' => $line->hs_code, 'productDescription' => $line->description,
                'rate' => $line->fbr_rate_id, 'uoM' => $line->unit,
                'quantity' => $this->decimal($line->quantity_milli, 3),
                'valueSalesExcludingST' => $this->decimal($line->taxable_amount, 2),
                'salesTaxApplicable' => $this->decimal($line->tax_amount, 2),
                'extraTax' => $this->decimal($line->other_tax_amount, 2),
                'furtherTax' => $this->decimal($line->advance_tax_amount, 2),
                'salesTaxWithheldAtSource' => $this->decimal($line->withholding_tax_amount, 2),
                'sroScheduleNo' => $line->sro_schedule_id, 'sroItemSerialNo' => $line->sro_item_id, 'saleType' => $line->sales_type,
            ])->all(),
        ];
    }

    private function decimal(int $value, int $scale): string
    {
        $digits = str_pad((string) $value, $scale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
