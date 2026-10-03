<?php

namespace App\Models;

use Database\Factories\EmployeeExpenseClaimFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeExpenseClaim extends Model
{
    /** @use HasFactory<EmployeeExpenseClaimFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'category_id', 'title', 'description', 'amount_minor', 'currency', 'expense_date', 'created_by'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'version' => 'integer', 'expense_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeExpenseCategory::class, 'category_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmployeeExpenseClaimEvent::class);
    }
}
