<?php

namespace App\Models;

use Database\Factories\EmployeeRotaSlotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeRotaSlot extends Model
{
    /** @use HasFactory<EmployeeRotaSlotFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_rota_id', 'employee_shift_id', 'shift_date', 'start_time', 'end_time', 'required_coverage', 'created_by'];

    protected function casts(): array
    {
        return ['shift_date' => 'date', 'required_coverage' => 'integer', 'version' => 'integer'];
    }
}
