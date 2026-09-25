<?php

namespace App\Models;

use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'category', 'subject', 'body_html', 'body_text', 'allowed_variables', 'is_active', 'archived_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['allowed_variables' => 'array', 'is_active' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
