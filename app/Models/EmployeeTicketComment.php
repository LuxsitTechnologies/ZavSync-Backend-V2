<?php

namespace App\Models;

use Database\Factories\EmployeeTicketCommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTicketComment extends Model
{
    /** @use HasFactory<EmployeeTicketCommentFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'employee_ticket_id', 'author_id', 'body', 'request_key_hash', 'payload_hash', 'created_at'];

    protected $hidden = ['request_key_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EmployeeTicket::class, 'employee_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
