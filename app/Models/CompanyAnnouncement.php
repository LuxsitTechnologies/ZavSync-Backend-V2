<?php

namespace App\Models;

use Database\Factories\CompanyAnnouncementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAnnouncement extends Model
{
    /** @use HasFactory<CompanyAnnouncementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'title', 'description', 'priority', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'published_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
