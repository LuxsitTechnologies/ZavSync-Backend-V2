<?php

namespace App\Models;

use Database\Factories\EmployeeTeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeTeam extends Model
{
    /** @use HasFactory<EmployeeTeamFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EmployeeTeamMembership::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }
}
