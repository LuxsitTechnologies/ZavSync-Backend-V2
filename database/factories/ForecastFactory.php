<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Forecast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Forecast>
 */
class ForecastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'fiscal_year_id' => FiscalYear::factory(), 'name' => 'Q2 Forecast', 'version' => 1, 'status' => 'draft', 'currency' => 'PKR', 'actuals_through' => null, 'description' => null, 'is_active' => false, 'created_by' => User::factory()];
    }
}
