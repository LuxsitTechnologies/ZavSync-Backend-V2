<?php

namespace App\Models;

use Database\Factories\OutreachSuppressionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachSuppression extends Model
{
    /** @use HasFactory<OutreachSuppressionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'email', 'normalized_email', 'reason', 'source', 'details', 'is_active', 'suppressed_at', 'removed_at', 'created_by', 'removed_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'suppressed_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
