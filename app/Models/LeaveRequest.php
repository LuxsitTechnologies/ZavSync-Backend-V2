<?php

namespace App\Models;

use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'leave_entitlement_id', 'type_name_snapshot', 'is_paid_snapshot', 'start_date', 'end_date', 'day_portion', 'units', 'status', 'reason', 'request_key_hash', 'payload_hash', 'submitted_by'];

    protected $hidden = ['request_key_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'is_paid_snapshot' => 'boolean', 'units' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeaveRequestEvent::class);
    }
}
