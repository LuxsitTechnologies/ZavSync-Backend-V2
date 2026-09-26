<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AuditService;
use App\Services\Platform\CompanySettingsService;
use App\Services\Platform\DocumentService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class CompanySettingController extends Controller
{
    public function __construct(private readonly CompanySettingsService $settings, private readonly DocumentService $documents, private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.settings.view');

        return response()->json($this->settings->get(Company::query()->findOrFail($companyId)));
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');
        $data = $request->validate([
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'], 'trading_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'], 'tax_identifier' => ['nullable', 'string', 'max:100'],
            'cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'], 'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'website' => ['nullable', 'url', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'country_code' => ['sometimes', 'string', 'size:2'], 'timezone' => ['sometimes', 'timezone'], 'base_currency' => ['sometimes', 'string', 'size:3'],
            'date_format' => ['sometimes', 'in:DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD'], 'time_format' => ['sometimes', 'in:12h,24h'],
            'number_format' => ['sometimes', 'in:1,234.56,1.234,56'], 'fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'default_payment_terms_days' => ['sometimes', 'integer', 'between:0,365'], 'default_warehouse_id' => ['nullable', 'uuid'],
            'default_financial_account_id' => ['nullable', 'uuid'], 'invoice_prefix' => ['sometimes', 'alpha_dash', 'max:20'],
            'purchase_prefix' => ['sometimes', 'alpha_dash', 'max:20'],
            'outreach_physical_address' => ['nullable', 'string', 'max:2000'], 'outreach_footer' => ['nullable', 'string', 'max:5000'],
            'outreach_open_tracking_enabled' => ['sometimes', 'boolean'], 'outreach_click_tracking_enabled' => ['sometimes', 'boolean'],
            'outreach_unsubscribe_required' => ['sometimes', 'accepted'],
            'ai_conversation_retention_days' => ['sometimes', 'integer', 'between:30,3650'],
            'ai_usage_retention_days' => ['sometimes', 'integer', 'between:30,3650'],
            'ai_allow_external_provider' => ['sometimes', 'boolean'],
            'ai_scheduled_intelligence_enabled' => ['sometimes', 'boolean'],
        ]);
        $company = Company::query()->findOrFail($companyId);
        $old = $this->settings->get($company)->toArray();
        $settings = $this->settings->update($company, $data, $request->user());
        $this->audit->record($request, $request->user(), $companyId, 'settings_updated', 'platform', $settings, $old, $settings->toArray());

        return response()->json($settings);
    }

    public function logo(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');
        $request->validate(['logo' => ['required', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048), 'extensions:png,jpg,jpeg,webp']]);
        $company = Company::query()->findOrFail($companyId);
        $document = $this->documents->store($companyId, $request->user(), 'company', $companyId, 'logo', $request->file('logo'));
        $settings = $this->settings->get($company);
        $old = $settings->toArray();
        $settings->update(['logo_path' => $document->id, 'updated_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $companyId, 'company_logo_updated', 'platform', $settings, $old, $settings->fresh()->toArray());

        return response()->json(['document' => $document, 'settings' => $settings->fresh()]);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
