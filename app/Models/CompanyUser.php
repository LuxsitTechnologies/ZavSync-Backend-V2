<?php

namespace App\Models;

use Database\Factories\CompanyUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyUser extends Model
{
    /** @use HasFactory<CompanyUserFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'user_id', 'role_id', 'is_active'];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
