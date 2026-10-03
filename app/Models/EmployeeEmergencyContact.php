<?php

namespace App\Models;

use Database\Factories\EmployeeEmergencyContactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeEmergencyContact extends Model
{
    /** @use HasFactory<EmployeeEmergencyContactFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['company_id', 'employee_id', 'name', 'relationship', 'phone', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'relationship' => 'encrypted', 'phone' => 'encrypted', 'version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
