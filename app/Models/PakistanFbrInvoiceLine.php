<?php

namespace App\Models;

use Database\Factories\PakistanFbrInvoiceLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PakistanFbrInvoiceLine extends Model
{
    /** @use HasFactory<PakistanFbrInvoiceLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['invoice_id', 'legacy_source_id', 'position', 'description', 'hs_code', 'quantity_milli', 'unit', 'unit_price', 'subtotal', 'discount', 'taxable_amount', 'tax_rate_bps', 'fbr_rate_id', 'tax_amount', 'other_tax_rate_bps', 'other_tax_amount', 'advance_tax_rate_bps', 'advance_tax_amount', 'withholding_tax_rate_bps', 'withholding_tax_amount', 'sro_schedule_id', 'sro_item_id', 'total', 'sales_type', 'legacy_original_values'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'quantity_milli' => 'integer', 'unit_price' => 'integer', 'subtotal' => 'integer', 'discount' => 'integer', 'taxable_amount' => 'integer', 'tax_rate_bps' => 'integer', 'tax_amount' => 'integer', 'other_tax_rate_bps' => 'integer', 'other_tax_amount' => 'integer', 'advance_tax_rate_bps' => 'integer', 'advance_tax_amount' => 'integer', 'withholding_tax_rate_bps' => 'integer', 'withholding_tax_amount' => 'integer', 'total' => 'integer', 'legacy_original_values' => 'array'];
    }

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            if (! $line->invoice->isEditable()) {
                throw new LogicException('The FBR Invoice lines are immutable.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PakistanFbrInvoice::class, 'invoice_id');
    }
}
