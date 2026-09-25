<?php

namespace App\Models;

use Database\Factories\OutreachSequenceStepFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachSequenceStep extends Model
{
    /** @use HasFactory<OutreachSequenceStepFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence_id', 'template_id', 'position', 'type', 'subject', 'body_html', 'body_text', 'wait_minutes'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'wait_minutes' => 'integer'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(OutreachSequence::class, 'sequence_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }
}
