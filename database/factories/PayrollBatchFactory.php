<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollBatch;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollBatch>
 */
class PayrollBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_period_id' => PayrollPeriod::factory(), 'sequence' => fake()->unique()->numberBetween(1, 999999), 'number' => fake()->unique()->bothify('PAY-2026-####'), 'status' => 'DRAFT', 'accounting_date' => '2026-09-30', 'employee_count' => 0, 'gross_earnings' => 0, 'taxable_earnings' => 0, 'employee_deductions' => 0, 'employee_contributions' => 0, 'tax_amount' => 0, 'employer_contributions' => 0, 'reimbursements' => 0, 'net_pay' => 0, 'employer_total_cost' => 0, 'journal_id' => null, 'reversal_journal_id' => null, 'correction_of_batch_id' => null, 'correction_reason' => null, 'created_by' => User::factory(), 'reviewed_by' => null, 'reviewed_at' => null, 'approved_by' => null, 'approved_at' => null, 'posted_by' => null, 'posted_at' => null, 'corrected_by' => null, 'corrected_at' => null, 'posting_idempotency_key' => null, 'posting_idempotency_hash' => null];
    }
}
