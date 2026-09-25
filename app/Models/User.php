<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')->withPivot(['role_id', 'is_active'])->withTimestamps();
    }

    public function belongsToCompany(string $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->wherePivot('is_active', true)->exists();
    }

    public function hasCompanyPermission(string $companyId, string $permission): bool
    {
        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $this->getKey())
            ->where('is_active', true)
            ->where(function ($query) use ($permission): void {
                $query->whereHas('role.permissions', fn ($permissions) => $permissions->whereIn('name', [$permission, '*']))
                    ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->whereIn('name', [$permission, '*']));
            })
            ->exists();
    }

    /** @param string $token */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification(Crypt::encryptString((string) $token)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}
