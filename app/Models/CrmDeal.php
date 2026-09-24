<?php

namespace App\Models;

use Database\Factories\CrmDealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CrmDeal extends Model
{
    /** @use HasFactory<CrmDealFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'account_id', 'primary_contact_id', 'lead_origin_id', 'pipeline_id', 'pipeline_stage_id', 'owner_id', 'customer_id', 'customer_handoff_key', 'title', 'amount', 'currency', 'probability_bps', 'expected_close_date', 'actual_close_date', 'status', 'source', 'description', 'loss_reason', 'closed_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'probability_bps' => 'integer', 'expected_close_date' => 'date:Y-m-d', 'actual_close_date' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'account_id');
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'primary_contact_id');
    }

    public function leadOrigin(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_origin_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'pipeline_stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
