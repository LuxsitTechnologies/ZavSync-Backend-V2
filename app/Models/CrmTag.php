<?php

namespace App\Models;

use Database\Factories\CrmTagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTag extends Model
{
    /** @use HasFactory<CrmTagFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'normalized_name', 'color', 'created_by'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
