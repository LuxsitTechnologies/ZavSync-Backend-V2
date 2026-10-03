<?php

namespace App\Models;

use Database\Factories\EmployeeShiftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    /** @use HasFactory<EmployeeShiftFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'start_time', 'end_time', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
