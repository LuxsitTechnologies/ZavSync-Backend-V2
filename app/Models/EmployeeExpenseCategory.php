<?php

namespace App\Models;

use Database\Factories\EmployeeExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeExpenseCategory extends Model
{
    /** @use HasFactory<EmployeeExpenseCategoryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
