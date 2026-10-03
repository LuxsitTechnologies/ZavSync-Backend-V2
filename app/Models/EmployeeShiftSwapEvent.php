<?php

namespace App\Models;

use Database\Factories\EmployeeShiftSwapEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShiftSwapEvent extends Model
{
    /** @use HasFactory<EmployeeShiftSwapEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_shift_swap_id', 'event_type', 'swap_version', 'actor_id', 'reason', 'occurred_at'];

    protected function casts(): array
    {
        return ['swap_version' => 'integer', 'occurred_at' => 'datetime'];
    }
}
