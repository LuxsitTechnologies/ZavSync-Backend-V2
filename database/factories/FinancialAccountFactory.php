<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->company().' Bank', 'type' => 'bank', 'bank_name' => fake()->company(), 'account_title' => fake()->company(), 'masked_account_number' => '****'.fake()->numerify('####'), 'iban' => null, 'currency' => 'PKR', 'gl_account_id' => Account::factory(), 'opening_balance' => null, 'is_default' => false, 'is_active' => true, 'notes' => null, 'created_by' => User::factory(), 'updated_by' => null];
    }
}
