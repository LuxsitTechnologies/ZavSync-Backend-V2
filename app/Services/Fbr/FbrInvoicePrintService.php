<?php

namespace App\Services\Fbr;

use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\FbrCompanyConfiguration;
use App\Models\PakistanFbrInvoice;

class FbrInvoicePrintService
{
    /** @return array<string, mixed> */
    public function data(PakistanFbrInvoice $invoice): array
    {
        if ($invoice->print_snapshot !== null) {
            return $invoice->print_snapshot;
        }

        $data = $this->build($invoice);
        if ($invoice->document_state === 'DRAFT') {
            $data['watermark'] = 'DRAFT / NOT SUBMITTED TO FBR';
        } elseif ($invoice->is_historical) {
            $data['snapshot_status'] = 'HISTORICAL_PRINT_SNAPSHOT_UNAVAILABLE';
        } else {
            $data['snapshot_status'] = 'PREVIOUSLY_ISSUED_SNAPSHOT_UNAVAILABLE';
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function build(PakistanFbrInvoice $invoice, bool $captureCurrentSettings = false): array
    {
        $invoice->loadMissing('lines');
        $usesCurrentSettings = ! $invoice->is_historical && ($captureCurrentSettings || $invoice->document_state === 'DRAFT');
        $settings = $usesCurrentSettings ? CompanySetting::query()->where('company_id', $invoice->company_id)->first() : null;
        $configuration = $usesCurrentSettings ? FbrCompanyConfiguration::query()->where('company_id', $invoice->company_id)->first() : null;
        $logo = $settings?->logo_path === null ? null : Document::query()
            ->where('company_id', $invoice->company_id)->whereKey($settings->logo_path)->first();
        $seller = $invoice->seller_snapshot ?? (! $usesCurrentSettings ? null : [
            'business_name' => $configuration?->seller_business_name ?? $settings?->legal_name,
            'tax_identifier' => $configuration?->seller_tax_identifier ?? $settings?->tax_identifier,
            'province' => $configuration?->seller_province,
            'address' => $configuration?->seller_address ?? $settings?->address,
            'email' => $settings?->email,
            'phone' => $settings?->phone,
        ]);

        return [
            'document_type' => 'FBR_INVOICE', 'snapshot_version' => 1,
            'invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'invoice_type' => $invoice->invoice_type, 'sale_type' => $invoice->sale_type,
            'currency' => $invoice->currency, 'origin_province' => $invoice->origin_province,
            'destination_province' => $invoice->destination_province,
            'scenario_id' => $invoice->scenarioId(),
            'seller' => $seller, 'buyer' => $invoice->buyer_snapshot,
            'lines' => $invoice->lines->map(fn ($line): array => $line->only([
                'position', 'description', 'hs_code', 'quantity_milli', 'unit', 'unit_price',
                'subtotal', 'discount', 'taxable_amount', 'tax_rate_bps', 'fbr_rate_id', 'tax_amount',
                'other_tax_rate_bps', 'other_tax_amount', 'advance_tax_rate_bps', 'advance_tax_amount',
                'withholding_tax_rate_bps', 'withholding_tax_amount', 'sro_schedule_id', 'sro_item_id',
                'total', 'sales_type',
            ]))->all(),
            'subtotal' => $invoice->subtotal, 'discount' => $invoice->discount,
            'taxable_amount' => $invoice->taxable_amount, 'sales_tax' => $invoice->sales_tax,
            'extra_tax' => $invoice->other_tax, 'further_tax' => $invoice->advance_tax,
            'st_withheld' => $invoice->withholding_tax, 'total' => $invoice->total,
            'notes' => $invoice->notes ?: $settings?->fbr_default_notes,
            'portal' => ! $usesCurrentSettings ? null : [
                'logo_document_id' => $logo?->id, 'logo_checksum_sha256' => $logo?->checksum_sha256,
                'footer' => $settings?->fbr_document_footer,
                'declaration' => $settings?->fbr_document_declaration,
                'number_prefix_display' => $settings?->fbr_number_prefix_display,
                'show_seller_email' => $settings?->fbr_show_seller_email ?? true,
                'show_seller_phone' => $settings?->fbr_show_seller_phone ?? true,
                'show_seller_address' => $settings?->fbr_show_seller_address ?? true,
            ],
            'verification' => [
                'fbr_status' => $invoice->fbr_status->value,
                'fbr_reference_number' => $invoice->fbr_reference_number,
                'provider_metadata' => $invoice->fbr_response_metadata,
                'qr_content' => null,
            ],
            'buyer_verification' => ['status' => 'NOT_VERIFIED', 'provider' => null],
        ];
    }
}
