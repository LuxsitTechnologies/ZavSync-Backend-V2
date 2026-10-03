<?php

namespace App\Models;

use Database\Factories\EmployeeRotaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeRota extends Model
{
    /** @use HasFactory<EmployeeRotaFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'timezone', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'published_at' => 'datetime', 'version' => 'integer'];
    }
}
