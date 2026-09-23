<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountMapping>
 */
class AccountMappingFactory extends Factory
{
    protected $model = AccountMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'key' => 'accounts_receivable', 'account_id' => Account::factory(), 'updated_by' => null];
    }
}
