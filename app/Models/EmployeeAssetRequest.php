<?php

namespace App\Models;

use Database\Factories\EmployeeAssetRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAssetRequest extends Model
{
    /** @use HasFactory<EmployeeAssetRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'type', 'item_description', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmployeeAssetRequestEvent::class);
    }
}
