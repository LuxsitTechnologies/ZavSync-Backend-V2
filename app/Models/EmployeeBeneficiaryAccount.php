<?php

namespace App\Models;

use Database\Factories\EmployeeBeneficiaryAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBeneficiaryAccount extends Model
{
    /** @use HasFactory<EmployeeBeneficiaryAccountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'account_holder_name', 'account_number', 'bank_name', 'branch_name', 'swift_code', 'created_by', 'updated_by'];

    protected $hidden = ['account_holder_name', 'account_number', 'bank_name', 'branch_name', 'swift_code'];

    protected function casts(): array
    {
        return ['account_holder_name' => 'encrypted', 'account_number' => 'encrypted', 'bank_name' => 'encrypted',
            'branch_name' => 'encrypted', 'swift_code' => 'encrypted', 'version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
