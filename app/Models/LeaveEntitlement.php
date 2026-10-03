<?php

namespace App\Models;

use Database\Factories\LeaveEntitlementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveEntitlement extends Model
{
    /** @use HasFactory<LeaveEntitlementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'year', 'allocated_units', 'created_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'allocated_units' => 'integer'];
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

    public function adjustments(): HasMany
    {
        return $this->hasMany(LeaveEntitlementAdjustment::class);
    }
}
