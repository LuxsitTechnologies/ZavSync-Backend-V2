<?php

namespace App\Models;

use Database\Factories\PayrollEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEntry extends Model
{
    /** @use HasFactory<PayrollEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_batch_id', 'employee_id', 'employee_payroll_profile_id', 'employee_code', 'employee_name', 'department', 'designation', 'base_salary', 'currency', 'profile_snapshot', 'statutory_rule_snapshot', 'gross_earnings', 'taxable_earnings', 'employee_deductions', 'employee_contributions', 'tax_amount', 'employer_contributions', 'reimbursements', 'net_pay', 'employer_total_cost'];

    protected function casts(): array
    {
        return ['base_salary' => 'integer', 'profile_snapshot' => 'array', 'statutory_rule_snapshot' => 'array', 'gross_earnings' => 'integer', 'taxable_earnings' => 'integer', 'employee_deductions' => 'integer', 'employee_contributions' => 'integer', 'tax_amount' => 'integer', 'employer_contributions' => 'integer', 'reimbursements' => 'integer', 'net_pay' => 'integer', 'employer_total_cost' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class, 'employee_payroll_profile_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PayrollPaymentAllocation::class);
    }
}
