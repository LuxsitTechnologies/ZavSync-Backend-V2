<?php

namespace App\Models;

use Database\Factories\EmployeeTicketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeTicket extends Model
{
    /** @use HasFactory<EmployeeTicketFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'created_by', 'subject', 'description', 'category', 'priority', 'status', 'version', 'request_key_hash', 'payload_hash'];

    protected $hidden = ['request_key_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmployeeTicketEvent::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EmployeeTicketComment::class);
    }
}
