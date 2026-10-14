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

    protected $attributes = ['dashboard_layout_columns' => 4, 'dashboard_layout_version' => 1, 'portal_settings_version' => 1];

    protected $fillable = ['company_id', 'legal_name', 'trading_name', 'registration_number', 'tax_identifier', 'cnic', 'email', 'phone', 'website', 'address', 'country_code', 'billing_country_confirmed_at', 'timezone', 'base_currency', 'date_format', 'time_format', 'number_format', 'fiscal_year_start_month', 'default_payment_terms_days', 'default_warehouse_id', 'default_financial_account_id', 'invoice_prefix', 'purchase_prefix', 'logo_path', 'dashboard_layout', 'dashboard_layout_columns', 'dashboard_layout_version', 'portal_settings_version', 'fbr_document_footer', 'fbr_document_declaration', 'fbr_default_notes', 'fbr_number_prefix_display', 'fbr_show_seller_email', 'fbr_show_seller_phone', 'fbr_show_seller_address', 'outreach_physical_address', 'outreach_footer', 'outreach_open_tracking_enabled', 'outreach_click_tracking_enabled', 'outreach_unsubscribe_required', 'ai_conversation_retention_days', 'ai_usage_retention_days', 'ai_allow_external_provider', 'ai_scheduled_intelligence_enabled', 'updated_by'];

    protected function casts(): array
    {
        return ['dashboard_layout' => 'array', 'dashboard_layout_columns' => 'integer', 'dashboard_layout_version' => 'integer', 'portal_settings_version' => 'integer', 'billing_country_confirmed_at' => 'datetime', 'fbr_show_seller_email' => 'boolean', 'fbr_show_seller_phone' => 'boolean', 'fbr_show_seller_address' => 'boolean', 'outreach_open_tracking_enabled' => 'boolean', 'outreach_click_tracking_enabled' => 'boolean', 'outreach_unsubscribe_required' => 'boolean', 'ai_conversation_retention_days' => 'integer', 'ai_usage_retention_days' => 'integer', 'ai_allow_external_provider' => 'boolean', 'ai_scheduled_intelligence_enabled' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
