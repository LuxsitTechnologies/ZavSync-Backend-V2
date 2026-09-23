<?php

namespace Database\Factories;

use App\Models\BankStatementImport;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementImport>
 */
class BankStatementImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'financial_account_id' => FinancialAccount::factory(), 'original_filename' => 'statement.csv', 'file_hash' => fake()->unique()->sha256(), 'statement_reference' => fake()->uuid(), 'statement_start_date' => now()->startOfMonth(), 'statement_end_date' => now()->endOfMonth(), 'opening_balance' => 0, 'closing_balance' => 0, 'status' => 'preview', 'column_mapping' => [], 'preview_rows' => [], 'row_count' => 0, 'imported_count' => 0, 'duplicate_count' => 0, 'idempotency_key' => fake()->uuid(), 'idempotency_hash' => fake()->sha256(), 'imported_by' => User::factory(), 'confirmed_at' => null];
    }
}
