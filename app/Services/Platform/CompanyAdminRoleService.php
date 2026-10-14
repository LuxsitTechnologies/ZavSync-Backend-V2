<?php

namespace App\Services\Platform;

use App\Models\CompanyInvitation;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompanyAdminRoleService
{
    private const LEGACY_NAMES = ['Finance Administrator', 'Company Administrator'];

    private const NON_COMPANY_ADMIN_PERMISSIONS = [
        'migration.view', 'migration.manage',
    ];

    private const APPROVED_PERMISSIONS = [
        'accounting.view', 'accounting.create', 'accounting.edit', 'accounting.post', 'accounting.periods.manage',
        'suppliers.view', 'suppliers.manage', 'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
        'purchase_orders.approve', 'purchase_orders.cancel', 'purchase_orders.receive', 'supplier_bills.view',
        'supplier_bills.manage', 'supplier_bills.post', 'supplier_payments.create', 'payables.view',
        'inventory.view', 'inventory.manage', 'inventory.adjust', 'inventory.transfer', 'inventory.valuation', 'inventory.return',
        'banking.view', 'banking.manage', 'banking.import', 'banking.reconcile', 'banking.post', 'banking.transfer',
        'banking.settlements', 'banking.cashflow',
        'budget.view', 'budget.manage', 'budget.submit', 'budget.approve', 'forecast.view', 'forecast.manage',
        'accounting.close.view', 'accounting.period.close', 'accounting.period.reopen', 'accounting.year.close', 'accounting.year.reopen',
        'employee.self.view', 'employee.profile.edit', 'employee.links.manage', 'employee.payroll.view',
        'employee.attendance.view', 'employee.attendance.clock', 'employee.attendance.correction.request',
        'attendance.view', 'attendance.manage', 'attendance.corrections.manage',
        'employee.leave.view', 'employee.leave.request', 'employee.leave.cancel',
        'leave.view', 'leave.manage', 'leave.approve', 'holiday.view', 'holiday.manage',
        'employee.tasks.view', 'employee.tasks.update', 'employee.tasks.comment',
        'employee.tickets.view', 'employee.tickets.create', 'employee.tickets.comment',
        'employee.documents.view', 'employee.documents.upload', 'employee.documents.admin.view', 'employee.documents.issue', 'employee.documents.release',
        'employee.announcements.view', 'announcements.view', 'announcements.manage', 'announcements.publish',
        'employee.directory.view', 'employee.teams.view', 'teams.view', 'teams.manage',
        'employee.assets.view', 'employee.assets.request', 'assets.view', 'assets.decide',
        'employee.expenses.view', 'employee.expenses.create', 'employee.expenses.edit', 'employee.expenses.submit',
        'expenses.view', 'expenses.categories.manage', 'expenses.approve',
        'employee.schedule.view', 'employee.schedule.swap.request', 'employee.schedule.swap.respond',
        'schedules.view', 'schedules.manage', 'schedules.swaps.decide',
        'tasks.view', 'tasks.manage', 'tasks.assign', 'tickets.view', 'tickets.manage',
        'payroll.view', 'payroll.manage', 'payroll.calculate', 'payroll.review', 'payroll.approve', 'payroll.post', 'payroll.release',
        'payroll.pay', 'payroll.settle-liabilities', 'payroll.reports', 'payroll.configure',
        'crm.view', 'crm.accounts.manage', 'crm.contacts.manage', 'crm.leads.manage', 'crm.deals.manage',
        'crm.activities.manage', 'crm.pipelines.manage', 'crm.import', 'crm.scoring.manage',
        'crm.customer.convert', 'crm.reports.view',
        'outreach.view', 'outreach.manage', 'outreach.send', 'outreach.templates.manage',
        'outreach.sequences.manage', 'outreach.providers.manage', 'outreach.suppressions.manage', 'outreach.reports.view',
        'platform.users.view', 'platform.users.manage', 'platform.invitations.manage',
        'platform.roles.view', 'platform.roles.manage', 'platform.settings.view', 'platform.settings.manage', 'platform.settings.high-risk',
        'platform.subscription.view', 'platform.subscription.manage', 'platform.plans.manage', 'billing.view', 'billing.manage',
        'platform.companies.view', 'platform.companies.approve', 'platform.companies.activate',
        'platform.documents.view', 'platform.documents.manage', 'platform.audit.view', 'platform.security.view', 'platform.security.manage',
        'platform.jobs.view', 'platform.jobs.manage', 'platform.exports.view', 'platform.exports.manage',
        'fbr.configuration.view', 'fbr.configuration.manage', 'migration.view', 'migration.manage',
        'pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit', 'fbr.reconcile', 'fbr.hs.manage',
        'portal.settings.view', 'portal.settings.manage',
        'ai.copilot.use', 'ai.knowledge.view', 'ai.knowledge.manage', 'ai.tools.use',
        'ai.actions.propose', 'ai.actions.review', 'ai.actions.approve', 'ai.actions.execute',
        'ai.providers.view', 'ai.providers.manage', 'ai.usage.view', 'ai.evaluations.view', 'ai.evaluations.manage',
        'intelligence.view', 'intelligence.manage', 'intelligence.anomalies.view', 'intelligence.forecasts.view',
        'intelligence.scenarios.manage', 'intelligence.briefings.view', 'intelligence.schedule.manage',
        'intelligence.calendar.manage', 'intelligence.observability.view', 'intelligence.evaluations.manage',
    ];

    private const REQUIRED_COMPANY_PERMISSIONS = [
        'billing.view', 'billing.manage',
        'portal.settings.view', 'portal.settings.manage',
    ];

    public function provision(string $companyId): Role
    {
        return DB::transaction(function () use ($companyId): Role {
            $roles = Role::query()->where('company_id', $companyId)
                ->whereIn('name', ['Admin', ...self::LEGACY_NAMES])->lockForUpdate()->get();
            $admin = $roles->firstWhere('name', 'Admin');
            if ($admin !== null && ! $admin->is_system) {
                throw new RuntimeException('A custom Admin role needs manual resolution before system-role migration.');
            }
            $legacy = $roles->filter(fn (Role $role) => $role->is_system && in_array($role->name, self::LEGACY_NAMES, true));
            if ($admin === null) {
                $admin = $legacy->first() ?? Role::query()->create([
                    'company_id' => $companyId, 'name' => 'Admin', 'is_system' => true,
                ]);
                if ($admin->name !== 'Admin') {
                    $admin->update(['name' => 'Admin']);
                }
            }
            $admin->update(['is_system' => true, 'archived_at' => null]);
            $now = now();
            DB::table('permissions')->insertOrIgnore(array_map(
                fn (string $name): array => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
                array_unique([...self::APPROVED_PERMISSIONS, ...self::REQUIRED_COMPANY_PERMISSIONS]),
            ));
            $admin->permissions()->sync(Permission::query()->whereIn('name', self::APPROVED_PERMISSIONS)
                ->whereNotIn('name', ['*', ...PlatformAuthority::PERMISSIONS, ...self::NON_COMPANY_ADMIN_PERMISSIONS])
                ->where('name', 'not like', 'platform.companies.%')
                ->where('name', 'not like', 'platform.jobs.%')
                ->where('name', 'not like', 'platform.plans.%')
                ->where('name', 'not like', 'fbr.hs.%')->pluck('id'));

            foreach ($legacy as $old) {
                if ($old->id === $admin->id) {
                    continue;
                }
                CompanyInvitation::query()->where('company_id', $companyId)->whereJsonContains('role_ids', $old->id)
                    ->each(function (CompanyInvitation $invitation) use ($old, $admin): void {
                        $invitation->update(['role_ids' => collect($invitation->role_ids)
                            ->map(fn (int $id): int => $id === $old->id ? $admin->id : $id)
                            ->unique()->values()->all()]);
                    });
                DB::table('company_users')->where('role_id', $old->id)->update(['role_id' => $admin->id]);
                $membershipIds = DB::table('company_user_role')->where('role_id', $old->id)->pluck('company_user_id');
                foreach ($membershipIds as $membershipId) {
                    DB::table('company_user_role')->insertOrIgnore(['company_user_id' => $membershipId, 'role_id' => $admin->id]);
                }
                $old->permissions()->detach();
                DB::table('company_user_role')->where('role_id', $old->id)->delete();
                $old->delete();
            }

            return $admin;
        });
    }
}
