<?php

namespace App\Models;

use Database\Factories\PayrollBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollBatch extends Model
{
    /** @use HasFactory<PayrollBatchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_period_id', 'sequence', 'number', 'status', 'accounting_date', 'employee_count', 'gross_earnings', 'taxable_earnings', 'employee_deductions', 'employee_contributions', 'tax_amount', 'employer_contributions', 'reimbursements', 'net_pay', 'employer_total_cost', 'journal_id', 'reversal_journal_id', 'correction_of_batch_id', 'correction_reason', 'created_by', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'corrected_by', 'corrected_at', 'posting_idempotency_key', 'posting_idempotency_hash'];

    protected function casts(): array
    {
        return ['accounting_date' => 'date:Y-m-d', 'employee_count' => 'integer', 'gross_earnings' => 'integer', 'taxable_earnings' => 'integer', 'employee_deductions' => 'integer', 'employee_contributions' => 'integer', 'tax_amount' => 'integer', 'employer_contributions' => 'integer', 'reimbursements' => 'integer', 'net_pay' => 'integer', 'employer_total_cost' => 'integer', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'corrected_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PayrollLiabilitySettlement::class);
    }
}
