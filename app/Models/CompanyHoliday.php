<?php

namespace App\Models;

use Database\Factories\CompanyHolidayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyHoliday extends Model
{
    /** @use HasFactory<CompanyHolidayFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'date', 'description', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
