<?php

namespace App\Models;

use Database\Factories\EmployeePayrollComponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollComponent extends Model
{
    /** @use HasFactory<EmployeePayrollComponentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_payroll_profile_id', 'payroll_component_id', 'fixed_amount', 'rate_bps', 'effective_from', 'effective_to', 'is_active'];

    protected function casts(): array
    {
        return ['fixed_amount' => 'integer', 'rate_bps' => 'integer', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class, 'employee_payroll_profile_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
