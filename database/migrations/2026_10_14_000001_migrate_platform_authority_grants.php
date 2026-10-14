<?php

use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Platform\PlatformAuthority;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public const DATA_ONLY = true;

    public function up(): void
    {
        $authority = app(PlatformAuthority::class);
        User::query()->where('is_platform_admin', true)->each(function (User $user) use ($authority): void {
            $legacy = CompanyUser::query()->with(['role.permissions', 'roles.permissions'])
                ->where('user_id', $user->id)->where('is_active', true)->get()
                ->flatMap(fn (CompanyUser $membership) => collect([$membership->role])->merge($membership->roles)->filter()
                    ->flatMap(fn ($role) => $role->permissions))
                ->filter(fn ($permission) => in_array($permission->name, PlatformAuthority::PERMISSIONS, true))
                ->pluck('id')->unique()->all();
            $authority->grant($user, $legacy);
        });
    }

    public function down(): void
    {
        // Do not remove migrated platform grants from users on rollback.
    }
};
