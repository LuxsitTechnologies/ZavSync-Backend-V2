<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyNavigationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyNavigationPreference>
 */
class CompanyNavigationPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'item_key' => 'fbr.invoicing',
            'is_visible' => false,
            'updated_by' => User::factory(),
        ];
    }
}
