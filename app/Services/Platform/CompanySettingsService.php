<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CompanySettingsService
{
    public function get(Company $company): CompanySetting
    {
        return CompanySetting::query()->firstOrCreate(['company_id' => $company->id], [
            'legal_name' => $company->name, 'timezone' => $company->timezone, 'base_currency' => $company->currency,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Company $company, array $data, User $user): CompanySetting
    {
        $settings = $this->get($company);
        $highRisk = Arr::only($data, ['base_currency', 'timezone', 'fiscal_year_start_month', 'invoice_prefix', 'purchase_prefix', 'registration_number', 'tax_identifier', 'cnic']);
        $changedHighRisk = collect($highRisk)->contains(fn ($value, $key) => $settings->getAttribute($key) !== $value);
        if ($changedHighRisk && ! $user->hasCompanyPermission($company->id, 'platform.settings.high-risk')) {
            throw new PlatformException('PERMISSION_DENIED', 'Elevated permission is required for high-risk settings.', 403);
        }
        if (array_key_exists('base_currency', $highRisk) && $settings->base_currency !== $highRisk['base_currency'] && Journal::query()->where('company_id', $company->id)->exists()) {
            throw new PlatformException('HISTORICAL_SETTING_LOCKED', 'Base currency cannot change after financial activity exists.', 409);
        }
        if (array_key_exists('fiscal_year_start_month', $highRisk) && $settings->fiscal_year_start_month !== $highRisk['fiscal_year_start_month'] && FiscalYear::query()->where('company_id', $company->id)->exists()) {
            throw new PlatformException('HISTORICAL_SETTING_LOCKED', 'Fiscal-year start cannot change after fiscal years exist.', 409);
        }
        if (! empty($data['default_warehouse_id']) && ! Warehouse::query()->where('company_id', $company->id)->whereKey($data['default_warehouse_id'])->exists()) {
            throw new PlatformException('TENANT_ACCESS_DENIED', 'The default warehouse is unavailable in this company.', 404);
        }
        if (! empty($data['default_financial_account_id']) && ! FinancialAccount::query()->where('company_id', $company->id)->whereKey($data['default_financial_account_id'])->exists()) {
            throw new PlatformException('TENANT_ACCESS_DENIED', 'The default financial account is unavailable in this company.', 404);
        }

        return DB::transaction(function () use ($company, $data, $settings, $user): CompanySetting {
            $settings->update([...$data, 'updated_by' => $user->id]);
            $company->update([
                'name' => $data['legal_name'] ?? $company->name,
                'currency' => $data['base_currency'] ?? $company->currency,
                'timezone' => $data['timezone'] ?? $company->timezone,
            ]);

            return $settings->fresh();
        });
    }
}
