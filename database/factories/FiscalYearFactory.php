<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => 'FY 2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'currency' => 'PKR', 'status' => 'open', 'created_by' => User::factory()];
    }
}
