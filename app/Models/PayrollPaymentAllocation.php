<?php

namespace App\Models;

use Database\Factories\PayrollPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPaymentAllocation extends Model
{
    /** @use HasFactory<PayrollPaymentAllocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_payment_id', 'payroll_entry_id', 'amount'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayrollPayment::class, 'payroll_payment_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }
}
