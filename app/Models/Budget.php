<?php

namespace App\Models;

use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'fiscal_year_id', 'based_on_budget_id', 'name', 'version', 'status', 'currency', 'description', 'is_active', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'activated_by', 'activated_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_budget_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
