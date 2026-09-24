<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollEntry;
use App\Models\PayrollPayment;
use App\Models\PayrollPaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPaymentAllocation>
 */
class PayrollPaymentAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_payment_id' => PayrollPayment::factory(), 'payroll_entry_id' => PayrollEntry::factory(), 'amount' => 100_000_00];
    }
}
