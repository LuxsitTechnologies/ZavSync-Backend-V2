<?php

namespace App\Models;

use Database\Factories\CrmLeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CrmLead extends Model
{
    /** @use HasFactory<CrmLeadFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'account_id', 'contact_id', 'owner_id', 'first_name', 'last_name', 'company_name', 'job_title', 'email', 'phone', 'mobile', 'website', 'source', 'status', 'estimated_value', 'currency', 'expected_timeframe', 'interest', 'notes', 'score', 'qualification_notes', 'converted_at', 'conversion_idempotency_key', 'converted_account_id', 'converted_contact_id', 'converted_deal_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['estimated_value' => 'integer', 'score' => 'integer', 'expected_timeframe' => 'date:Y-m-d', 'converted_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'converted_account_id');
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'converted_contact_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'converted_deal_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'activityable');
    }

    public function scoreEvents(): MorphMany
    {
        return $this->morphMany(CrmScoreEvent::class, 'scoreable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(CrmTag::class, 'taggable', 'crm_taggables')->withPivot('company_id');
    }
}
