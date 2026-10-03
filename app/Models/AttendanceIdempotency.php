<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceIdempotency extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'key_hash', 'payload_hash', 'action', 'response_snapshot'];

    protected function casts(): array
    {
        return ['response_snapshot' => 'array'];
    }
}
