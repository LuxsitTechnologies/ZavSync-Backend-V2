<?php

namespace App\Models;

use Database\Factories\OutreachMessageLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachMessageLink extends Model
{
    /** @use HasFactory<OutreachMessageLinkFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'message_id', 'token_hash', 'destination_url', 'first_clicked_at', 'click_count'];

    protected function casts(): array
    {
        return ['first_clicked_at' => 'datetime', 'click_count' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutreachMessage::class, 'message_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
