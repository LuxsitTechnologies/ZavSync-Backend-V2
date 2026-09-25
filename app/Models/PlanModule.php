<?php

namespace App\Models;

use Database\Factories\PlanModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanModule extends Model
{
    /** @use HasFactory<PlanModuleFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $fillable = ['plan_id', 'module_key', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
