<?php

namespace App\Models;

use Database\Factories\AiProviderConfigurationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderConfiguration extends Model
{
    /** @use HasFactory<AiProviderConfigurationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'provider', 'chat_model', 'embedding_model', 'api_key', 'settings', 'is_enabled', 'updated_by'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'settings' => 'encrypted:array', 'is_enabled' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
