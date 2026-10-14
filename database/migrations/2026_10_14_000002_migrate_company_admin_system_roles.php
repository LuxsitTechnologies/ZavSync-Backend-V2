<?php

use App\Models\Role;
use App\Services\Platform\CompanyAdminRoleService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public const DATA_ONLY = true;

    public function up(): void
    {
        $service = app(CompanyAdminRoleService::class);
        Role::query()->where('is_system', true)
            ->whereIn('name', ['Finance Administrator', 'Company Administrator', 'Admin'])
            ->distinct()->pluck('company_id')->filter()
            ->each(fn (string $companyId) => $service->provision($companyId));
    }

    public function down(): void
    {
        // Role names, assignments, and permissions cannot be safely rolled back.
    }
};
