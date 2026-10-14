<?php

namespace App\Models;

use Database\Factories\BillingCataloguePriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingCataloguePrice extends Model
{
    /** @use HasFactory<BillingCataloguePriceFactory> */
    use HasFactory;

    protected $fillable = ['kind', 'code', 'currency', 'billing_interval', 'version', 'unit_amount_minor', 'is_purchasable', 'provider_price_id', 'retired_at'];

    protected $hidden = ['provider_price_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'unit_amount_minor' => 'integer', 'is_purchasable' => 'boolean', 'retired_at' => 'datetime'];
    }
}
