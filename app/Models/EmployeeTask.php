<?php

namespace App\Models;

use Database\Factories\EmployeeTaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeTask extends Model
{
    /** @use HasFactory<EmployeeTaskFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'assigned_employee_id', 'created_by', 'title', 'description', 'priority', 'due_date', 'status', 'completed_at', 'version', 'request_key_hash', 'payload_hash'];

    protected $hidden = ['request_key_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmployeeTaskEvent::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EmployeeTaskComment::class);
    }
}
