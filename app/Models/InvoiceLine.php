<?php

namespace App\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['invoice_id', 'position', 'item_id', 'item_name', 'description', 'quantity_milli', 'unit', 'unit_price', 'subtotal', 'discount', 'taxable_amount', 'tax_rate_bps', 'tax_amount', 'other_tax_rate_bps', 'other_tax_amount', 'advance_tax_rate_bps', 'advance_tax_amount', 'withholding_tax_rate_bps', 'withholding_tax_amount', 'total', 'sales_type', 'tax_metadata'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'quantity_milli' => 'integer', 'unit_price' => 'integer', 'subtotal' => 'integer', 'discount' => 'integer', 'taxable_amount' => 'integer', 'tax_rate_bps' => 'integer', 'tax_amount' => 'integer', 'other_tax_rate_bps' => 'integer', 'other_tax_amount' => 'integer', 'advance_tax_rate_bps' => 'integer', 'advance_tax_amount' => 'integer', 'withholding_tax_rate_bps' => 'integer', 'withholding_tax_amount' => 'integer', 'total' => 'integer', 'tax_metadata' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
