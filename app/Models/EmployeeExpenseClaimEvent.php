<?php

namespace App\Models;

use Database\Factories\EmployeeExpenseClaimEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeExpenseClaimEvent extends Model
{
    /** @use HasFactory<EmployeeExpenseClaimEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_expense_claim_id', 'event_type', 'claim_version', 'reason', 'actor_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['claim_version' => 'integer', 'occurred_at' => 'datetime'];
    }
}
