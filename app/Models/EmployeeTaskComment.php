<?php

namespace App\Models;

use Database\Factories\EmployeeTaskCommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaskComment extends Model
{
    /** @use HasFactory<EmployeeTaskCommentFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'employee_task_id', 'author_id', 'body', 'request_key_hash', 'payload_hash', 'created_at'];

    protected $hidden = ['request_key_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(EmployeeTask::class, 'employee_task_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
