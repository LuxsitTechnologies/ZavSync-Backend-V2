<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'legal_name' => fake()->company(), 'country_code' => 'PK',
            'timezone' => 'Asia/Karachi', 'base_currency' => 'PKR', 'fiscal_year_start_month' => 7,
        ];
    }
}
