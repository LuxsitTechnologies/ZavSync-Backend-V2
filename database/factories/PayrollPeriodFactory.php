<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'fiscal_year_id' => null, 'accounting_period_id' => null, 'name' => 'September 2026', 'frequency' => 'monthly', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'pay_date' => '2026-09-30', 'status' => 'open', 'created_by' => User::factory()];
    }
}
