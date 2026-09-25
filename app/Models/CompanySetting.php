<?php

namespace App\Models;

use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySetting extends Model
{
    /** @use HasFactory<CompanySettingFactory> */
    use HasFactory;

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['company_id', 'legal_name', 'trading_name', 'registration_number', 'tax_identifier', 'cnic', 'email', 'phone', 'website', 'address', 'country_code', 'timezone', 'base_currency', 'date_format', 'time_format', 'number_format', 'fiscal_year_start_month', 'default_payment_terms_days', 'default_warehouse_id', 'default_financial_account_id', 'invoice_prefix', 'purchase_prefix', 'logo_path', 'updated_by'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
