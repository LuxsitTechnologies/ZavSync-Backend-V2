<?php

namespace Database\Factories;

use App\Models\AccountingCloseRecord;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingCloseRecord>
 */
class AccountingCloseRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'close_type' => 'period', 'accounting_period_id' => AccountingPeriod::factory(), 'status' => 'closed', 'checklist_snapshot' => ['ready' => true, 'checks' => []], 'closed_by' => User::factory(), 'closed_at' => now()];
    }
}
