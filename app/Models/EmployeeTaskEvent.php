<?php

namespace App\Models;

use Database\Factories\EmployeeTaskEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaskEvent extends Model
{
    /** @use HasFactory<EmployeeTaskEventFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'employee_task_id', 'actor_id', 'action', 'from_status', 'to_status', 'from_employee_id', 'to_employee_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(EmployeeTask::class, 'employee_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
