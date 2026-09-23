<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Forecast;
use App\Models\ForecastLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastLine>
 */
class ForecastLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'forecast_id' => Forecast::factory(), 'account_id' => Account::factory(), 'accounting_period_id' => AccountingPeriod::factory(), 'amount' => fake()->numberBetween(10000, 1000000)];
    }
}
