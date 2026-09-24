<?php

namespace App\Models;

use Database\Factories\EmployeePayrollProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePayrollProfile extends Model
{
    /** @use HasFactory<EmployeePayrollProfileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'payroll_status', 'pay_frequency', 'base_salary', 'currency', 'effective_from', 'effective_to', 'tax_identifier', 'statutory_registration', 'payment_financial_account_id', 'employee_bank_reference', 'created_by'];

    protected function casts(): array
    {
        return ['base_salary' => 'integer', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'statutory_registration' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(EmployeePayrollComponent::class);
    }

    public function paymentFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'payment_financial_account_id');
    }
}
