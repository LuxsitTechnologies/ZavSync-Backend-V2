<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'code' => (string) fake()->unique()->numberBetween(100, 9999), 'name' => fake()->words(2, true), 'type' => 'asset', 'subtype' => 'current_asset', 'normal_balance' => 'debit', 'parent_id' => null, 'is_active' => true, 'is_system' => false, 'currency' => 'PKR', 'opening_balance' => 0, 'opening_balance_date' => null, 'created_by' => User::factory(), 'updated_by' => null];
    }

    public function liability(): static
    {
        return $this->state(fn (): array => ['type' => 'liability', 'subtype' => 'current_liability', 'normal_balance' => 'credit']);
    }

    public function equity(): static
    {
        return $this->state(fn (): array => ['type' => 'equity', 'subtype' => 'equity', 'normal_balance' => 'credit']);
    }

    public function revenue(): static
    {
        return $this->state(fn (): array => ['type' => 'revenue', 'subtype' => 'revenue', 'normal_balance' => 'credit']);
    }

    public function expense(string $subtype = 'operating_expense'): static
    {
        return $this->state(fn (): array => ['type' => 'expense', 'subtype' => $subtype, 'normal_balance' => 'debit']);
    }
}
