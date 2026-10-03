<?php

namespace App\Models;

use Database\Factories\EmployeeTeamMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTeamMembership extends Model
{
    /** @use HasFactory<EmployeeTeamMembershipFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_team_id', 'employee_id', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'left_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(EmployeeTeam::class, 'employee_team_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
