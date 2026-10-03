<?php

namespace App\Models;

use Database\Factories\EmployeeTicketEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTicketEvent extends Model
{
    /** @use HasFactory<EmployeeTicketEventFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'employee_ticket_id', 'actor_id', 'action', 'from_status', 'to_status', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EmployeeTicket::class, 'employee_ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
