<?php

namespace App\Models;

use Database\Factories\EmployeeShiftSwapFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShiftSwap extends Model
{
    /** @use HasFactory<EmployeeShiftSwapFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'requester_employee_id', 'target_employee_id', 'from_assignment_id',
        'to_assignment_id', 'from_assignment_version', 'to_assignment_version', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'from_assignment_version' => 'integer', 'to_assignment_version' => 'integer',
            'target_accepted_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
