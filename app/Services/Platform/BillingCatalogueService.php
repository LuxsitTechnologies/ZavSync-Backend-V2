<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\BillingCataloguePrice;
use App\Models\CompanySetting;
use Illuminate\Support\Collection;

class BillingCatalogueService
{
    /** @var array<string, array<int, string>> */
    private const INCLUDED_ADDONS = [
        'starter' => [],
        'professional' => ['fbr', 'crm', 'hrm_payroll'],
        'enterprise' => ['fbr', 'crm', 'hrm_payroll', 'inventory', 'ai'],
    ];

    /** @return array<int, string> */
    public function includedAddons(string $plan): array
    {
        return self::INCLUDED_ADDONS[$plan]
            ?? throw new PlatformException('BILLING_SELECTION_INVALID', 'Unknown plan.', 422);
    }

    /** @return array{country_code: string, currency: string} */
    public function billingJurisdiction(string $companyId): array
    {
        $settings = CompanySetting::query()->where('company_id', $companyId)->firstOrFail();
        if ($settings->billing_country_confirmed_at === null || ! preg_match('/^[A-Z]{2}$/', $settings->country_code)) {
            throw new PlatformException('BILLING_COUNTRY_REQUIRED', 'Confirm the company registered country before checkout.', 409);
        }

        return ['country_code' => $settings->country_code, 'currency' => $settings->country_code === 'PK' ? 'PKR' : 'USD'];
    }

    /** @return Collection<int, BillingCataloguePrice> */
    public function currentPrices(string $currency): Collection
    {
        return BillingCataloguePrice::query()->where('currency', $currency)->whereNull('retired_at')
            ->orderBy('kind')->orderBy('code')->orderBy('billing_interval')->orderByDesc('version')->get()
            ->unique(fn (BillingCataloguePrice $price): string => "{$price->kind}:{$price->code}:{$price->billing_interval}")->values();
    }

    public function price(string $kind, string $code, string $currency, string $interval): BillingCataloguePrice
    {
        $price = BillingCataloguePrice::query()->where('kind', $kind)->where('code', $code)
            ->where('currency', $currency)->where('billing_interval', $interval)->whereNull('retired_at')
            ->orderByDesc('version')->first();
        if ($price === null || ! $price->is_purchasable || $price->unit_amount_minor === null) {
            throw new PlatformException('BILLING_PRICE_UNAVAILABLE', 'The selected billing item is not priced or available.', 409);
        }

        return $price;
    }

    /** @return array{catalogue_price_id: int, kind: string, code: string, currency: string, billing_interval: string, version: int, unit_amount_minor: int, quantity: int, total_minor: int} */
    public function line(BillingCataloguePrice $price, int $quantity = 1): array
    {
        if ($quantity < 1 || $quantity > 100_000 || $price->unit_amount_minor === null
            || $price->unit_amount_minor > intdiv(PHP_INT_MAX, $quantity)) {
            throw new PlatformException('BILLING_QUANTITY_INVALID', 'The billing quantity is not supported.', 422);
        }

        return [
            'catalogue_price_id' => $price->id,
            'kind' => $price->kind,
            'code' => $price->code,
            'currency' => $price->currency,
            'billing_interval' => $price->billing_interval,
            'version' => $price->version,
            'unit_amount_minor' => $price->unit_amount_minor,
            'quantity' => $quantity,
            'total_minor' => $price->unit_amount_minor * $quantity,
        ];
    }

    /** @param array<int, string> $addons
     * @return array{country_code: string, currency: string, plan: string, billing_interval: string, lines: array<int, array<string, int|string>>, subtotal_minor: int, discount_minor: int, tax_minor: null, total_minor: int, tax_status: string}
     */
    public function quote(string $companyId, string $plan, string $interval, array $addons): array
    {
        if (! array_key_exists($plan, self::INCLUDED_ADDONS) || ! in_array($interval, ['monthly', 'annual'], true)) {
            throw new PlatformException('BILLING_SELECTION_INVALID', 'Select a supported plan and billing interval.', 422);
        }
        if (count($addons) !== count(array_unique($addons))) {
            throw new PlatformException('BILLING_SELECTION_INVALID', 'Select each add-on only once.', 422);
        }
        foreach ($addons as $addon) {
            if (! in_array($addon, ['fbr', 'crm', 'inventory', 'ai', 'hrm_payroll'], true)) {
                throw new PlatformException('BILLING_SELECTION_INVALID', 'An add-on is not supported.', 422);
            }
            if (in_array($addon, self::INCLUDED_ADDONS[$plan], true)) {
                throw new PlatformException('BILLING_ADDON_INCLUDED', 'The selected add-on is already included in this plan.', 409);
            }
        }
        $jurisdiction = $this->billingJurisdiction($companyId);
        $lines = [$this->line($this->price('plan', $plan, $jurisdiction['currency'], $interval))];
        foreach ($addons as $addon) {
            $lines[] = $this->line($this->price('addon', $addon, $jurisdiction['currency'], $interval));
        }
        $subtotal = 0;
        foreach ($lines as $line) {
            if ($subtotal > PHP_INT_MAX - $line['total_minor']) {
                throw new PlatformException('BILLING_AMOUNT_OVERFLOW', 'The billing amount is outside the supported range.', 422);
            }
            $subtotal += $line['total_minor'];
        }

        return [
            ...$jurisdiction, 'plan' => $plan, 'billing_interval' => $interval,
            'lines' => $lines, 'subtotal_minor' => $subtotal, 'discount_minor' => 0,
            'tax_minor' => null, 'total_minor' => $subtotal, 'tax_status' => 'EXCLUSIVE_TO_BE_CALCULATED_BY_PROVIDER',
        ];
    }

    /** @return array{users: int, employees: int, storage_mb: int} */
    public function includedLimits(string $plan): array
    {
        return match ($plan) {
            'starter' => ['users' => 3, 'employees' => 5, 'storage_mb' => 5 * 1024],
            'professional' => ['users' => 10, 'employees' => 25, 'storage_mb' => 25 * 1024],
            'enterprise' => ['users' => 50, 'employees' => 100, 'storage_mb' => 100 * 1024],
            default => throw new PlatformException('BILLING_SELECTION_INVALID', 'Unknown plan.', 422),
        };
    }

    /** @param array<int, string> $addons
     * @return array<int, string>
     */
    public function modulesFor(string $plan, array $addons = []): array
    {
        $base = ['accounting', 'invoicing', 'receivables'];
        if (in_array($plan, ['professional', 'enterprise'], true)) {
            $base = [...$base, 'fbr', 'hrm', 'payroll', 'crm', 'analytics'];
        }
        if ($plan === 'enterprise') {
            $base = [...$base, 'inventory', 'ai'];
        }
        foreach ($addons as $addon) {
            $base = [...$base, ...match ($addon) {
                'fbr' => ['fbr'],
                'crm' => ['crm'],
                'hrm_payroll' => ['hrm', 'payroll'],
                'inventory' => ['inventory'],
                'ai' => ['ai'],
                default => [],
            }];
        }

        return array_values(array_unique($base));
    }
}
