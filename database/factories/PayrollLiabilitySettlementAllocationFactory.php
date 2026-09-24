<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollEntryLine;
use App\Models\PayrollLiabilitySettlement;
use App\Models\PayrollLiabilitySettlementAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollLiabilitySettlementAllocation>
 */
class PayrollLiabilitySettlementAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_liability_settlement_id' => PayrollLiabilitySettlement::factory(), 'payroll_entry_line_id' => PayrollEntryLine::factory(), 'amount' => 10_000_00];
    }
}
