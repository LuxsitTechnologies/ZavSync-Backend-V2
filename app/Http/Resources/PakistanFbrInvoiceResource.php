<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PakistanFbrInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'company_id' => $this->company_id, 'domain' => 'pakistan_fbr', 'module_name' => 'FBR Invoicing',
            'customer_id' => $this->customer_id, 'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->format('Y-m-d'), 'due_date' => $this->due_date?->format('Y-m-d'),
            'document_state' => $this->document_state, 'is_historical' => $this->is_historical,
            'accounting_integration' => 'NOT_INTEGRATED', 'buyer_snapshot' => $this->buyer_snapshot,
            'invoice_type' => $this->invoice_type, 'sale_type' => $this->sale_type,
            'scenario_id' => $this->scenarioId(),
            'origin_province' => $this->origin_province, 'destination_province' => $this->destination_province,
            'currency' => $this->currency, 'subtotal' => $this->subtotal, 'discount' => $this->discount,
            'taxable_amount' => $this->taxable_amount, 'sales_tax' => $this->sales_tax,
            'extra_tax' => $this->other_tax, 'further_tax' => $this->advance_tax,
            'withholding_tax' => $this->withholding_tax, 'total' => $this->total,
            'historical_amount_paid' => $this->is_historical ? $this->amount_paid : null,
            'notes' => $this->notes, 'fbr_status' => $this->fbr_status->value, 'fbr_reference_number' => $this->fbr_reference_number,
            'editable' => $this->isEditable(), 'submission_blocked' => $this->is_historical,
            'capabilities' => [
                'accounting_posting' => false, 'payments' => false, 'amendment' => false, 'cancellation' => false,
                'retry_recovery' => ! $this->is_historical && $this->fbr_reference_number === null
                    && in_array($this->fbr_status->value, ['pending', 'failed'], true),
                'certified_print_qr' => false, 'scenario_fed_236g_236h' => false,
                'print_data' => true, 'regulatory_print_status' => 'STAGING_CERTIFICATION_REQUIRED',
                'qr_content' => null, 'buyer_registration_check' => false, 'reference_sync' => false,
                'provider_submission_enabled' => (bool) config('services.fbr.pakistan_submission_enabled', false),
            ],
            'historical' => $this->when($this->is_historical, fn (): array => [
                'legacy_status' => $this->legacy_status, 'accounting_state' => $this->historical_accounting_state,
                'reconciliation_state' => $this->migration_reconciliation_state,
            ]),
            'migration_metadata' => $this->when($this->is_historical && ($request->user()?->hasCompanyPermission((string) $this->company_id, 'migration.view') ?? false), fn (): array => [
                'source_system' => $this->legacy_source_system, 'source_id' => $this->legacy_source_id,
                'original_company_id' => $this->legacy_original_company_id, 'import_run_id' => $this->legacy_import_run_id,
                'original_timestamps' => $this->legacy_original_timestamps, 'original_financial_values' => $this->legacy_original_financial_values,
            ]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [...$line->only([
                'id', 'position', 'description', 'hs_code', 'quantity_milli', 'unit', 'unit_price',
                'subtotal', 'discount', 'taxable_amount', 'tax_rate_bps', 'fbr_rate_id', 'tax_amount',
                'other_tax_rate_bps', 'other_tax_amount', 'advance_tax_rate_bps', 'advance_tax_amount',
                'withholding_tax_rate_bps', 'withholding_tax_amount', 'sro_schedule_id', 'sro_item_id', 'total', 'sales_type',
            ]), 'sales_tax' => $line->tax_amount, 'extra_tax' => $line->other_tax_amount,
                'further_tax' => $line->advance_tax_amount, 'st_withheld' => $line->withholding_tax_amount])),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
