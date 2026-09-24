<?php

namespace App\Models;

use Database\Factories\CrmAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CrmAccount extends Model
{
    /** @use HasFactory<CrmAccountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'owner_id', 'customer_id', 'customer_handoff_key', 'name', 'legal_name', 'email', 'phone', 'website', 'ntn', 'cnic', 'registration_number', 'industry', 'account_type', 'address', 'city', 'country', 'postal_code', 'source', 'status', 'notes', 'is_archived', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'account_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'account_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'activityable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(CrmTag::class, 'taggable', 'crm_taggables')->withPivot('company_id');
    }
}
