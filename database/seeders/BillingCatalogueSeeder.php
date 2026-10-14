<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BillingCatalogueSeeder extends Seeder
{
    /** @var array<string, array<string, array<string, int>>> */
    private const MONTHLY_PRICES = [
        'PKR' => [
            'plan' => ['starter' => 299_900, 'professional' => 899_900, 'enterprise' => 2_499_900],
            'addon' => ['fbr' => 250_000, 'crm' => 350_000, 'inventory' => 350_000, 'ai' => 550_000, 'hrm_payroll' => 350_000],
            'seat' => ['employee_overage' => 25_000, 'user_overage' => 50_000],
        ],
        'USD' => [
            'plan' => ['starter' => 1_500, 'professional' => 4_900, 'enterprise' => 9_900],
            'addon' => ['fbr' => 1_000, 'crm' => 1_000, 'inventory' => 1_500, 'ai' => 2_500, 'hrm_payroll' => 1_500],
            'seat' => ['employee_overage' => 100, 'user_overage' => 300],
        ],
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::MONTHLY_PRICES as $currency => $kinds) {
            foreach ($kinds as $kind => $prices) {
                foreach ($prices as $code => $amount) {
                    $rows[] = $this->priceRow($kind, $code, $currency, 'monthly', $amount);
                    if ($kind !== 'seat') {
                        $annualAmount = intdiv($amount * 12 * 85 + 50, 100);
                        $rows[] = $this->priceRow($kind, $code, $currency, 'annual', $annualAmount);
                    }
                }
            }
            foreach (['storage_block_10gb' => 'storage', 'ai_overage' => 'usage'] as $code => $kind) {
                $rows[] = $this->priceRow($kind, $code, $currency, 'monthly', null);
            }
        }
        DB::table('billing_catalogue_prices')->insertOrIgnore($rows);
    }

    /** @return array<string, mixed> */
    private function priceRow(string $kind, string $code, string $currency, string $interval, ?int $amount): array
    {
        return [
            'kind' => $kind, 'code' => $code, 'currency' => $currency,
            'billing_interval' => $interval, 'version' => 1,
            'unit_amount_minor' => $amount, 'is_purchasable' => $amount !== null,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
