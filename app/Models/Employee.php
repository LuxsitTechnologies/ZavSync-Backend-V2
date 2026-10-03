<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_code', 'full_name', 'email', 'phone', 'department', 'designation', 'employment_type', 'status', 'joining_date', 'leaving_date', 'location', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['joining_date' => 'date:Y-m-d', 'leaving_date' => 'date:Y-m-d', 'address' => 'encrypted', 'self_profile_version' => 'integer', 'manager_version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyMembership(): HasOne
    {
        return $this->hasOne(CompanyUser::class);
    }

    public function payrollProfiles(): HasMany
    {
        return $this->hasMany(EmployeePayrollProfile::class);
    }

    public function currentPayrollProfile(): HasOne
    {
        return $this->hasOne(EmployeePayrollProfile::class)->latestOfMany('effective_from');
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_employee_id');
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(EmployeeTeamMembership::class);
    }
}
