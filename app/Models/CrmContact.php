<?php

namespace App\Models;

use Database\Factories\CrmContactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CrmContact extends Model
{
    /** @use HasFactory<CrmContactFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'account_id', 'owner_id', 'first_name', 'last_name', 'job_title', 'department', 'email', 'phone', 'mobile', 'is_primary', 'address', 'notes', 'status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'account_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
