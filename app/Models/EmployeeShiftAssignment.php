<?php

namespace App\Models;

use Database\Factories\EmployeeShiftAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShiftAssignment extends Model
{
    /** @use HasFactory<EmployeeShiftAssignmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_rota_slot_id', 'employee_id', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
