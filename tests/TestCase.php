<?php

namespace Tests;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** @param array<int, string> $permissions @return array{User, Company} */
    protected function actingAsCompanyUser(array $permissions): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Test role']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission);
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        return [$user, $company];
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{user:User,company:Company,customer:Customer,accounts:array<string,Account>}
     */
    protected function stage3AccountingContext(array $permissions = ['accounting.view', 'accounting.create', 'accounting.edit', 'accounting.post']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        AccountingPeriod::factory()->for($company)->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $accounts = [
            'accounts_receivable' => Account::factory()->for($company)->create(['code' => '1100', 'name' => 'Accounts Receivable', 'created_by' => $user->id]),
            'sales_revenue' => Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Sales Revenue', 'created_by' => $user->id]),
            'sales_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2020', 'name' => 'Sales Tax Payable', 'created_by' => $user->id]),
            'accounts_payable' => Account::factory()->for($company)->liability()->create(['code' => '2010', 'name' => 'Accounts Payable', 'created_by' => $user->id]),
            'other_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2030', 'name' => 'Other Tax Payable', 'created_by' => $user->id]),
            'advance_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2040', 'name' => 'Advance Tax Payable', 'created_by' => $user->id]),
            'withholding_tax_receivable' => Account::factory()->for($company)->create(['code' => '1200', 'name' => 'Withholding Tax Receivable', 'created_by' => $user->id]),
            'bank' => Account::factory()->for($company)->create(['code' => '1020', 'name' => 'Bank', 'created_by' => $user->id]),
            'cash' => Account::factory()->for($company)->create(['code' => '1010', 'name' => 'Cash', 'created_by' => $user->id]),
        ];
        foreach ($accounts as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $customer = Customer::factory()->for($company)->create(['created_by' => $user->id]);

        return compact('user', 'company', 'customer', 'accounts');
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{user:User,company:Company,supplier:Supplier,accounts:array<string,Account>}
     */
    protected function stage4AccountingContext(array $permissions = ['suppliers.view', 'suppliers.manage', 'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update', 'purchase_orders.approve', 'purchase_orders.cancel', 'purchase_orders.receive', 'supplier_bills.view', 'supplier_bills.manage', 'supplier_bills.post', 'supplier_payments.create', 'payables.view']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        AccountingPeriod::factory()->for($company)->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $accounts = [
            'accounts_payable' => Account::factory()->for($company)->liability()->create(['code' => '2010', 'name' => 'Accounts Payable', 'created_by' => $user->id]),
            'purchase_expense' => Account::factory()->for($company)->expense()->create(['code' => '6000', 'name' => 'Purchases', 'created_by' => $user->id]),
            'purchase_tax_recoverable' => Account::factory()->for($company)->create(['code' => '1210', 'name' => 'Purchase Tax Recoverable', 'created_by' => $user->id]),
            'withholding_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2050', 'name' => 'Withholding Tax Payable', 'created_by' => $user->id]),
            'bank' => Account::factory()->for($company)->create(['code' => '1020', 'name' => 'Bank', 'created_by' => $user->id]),
            'cash' => Account::factory()->for($company)->create(['code' => '1010', 'name' => 'Cash', 'created_by' => $user->id]),
        ];
        foreach ($accounts as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $supplier = Supplier::factory()->for($company)->create([
            'created_by' => $user->id, 'default_expense_account_id' => $accounts['purchase_expense']->id,
            'default_payable_account_id' => $accounts['accounts_payable']->id,
        ]);

        return compact('user', 'company', 'supplier', 'accounts');
    }

    /** @return array{user:User,company:Company,customer:Customer,supplier:Supplier,item:InventoryItem,warehouse:Warehouse,accounts:array<string,Account>} */
    protected function stage5AccountingContext(array $permissions = ['inventory.view', 'inventory.manage', 'inventory.adjust', 'inventory.transfer', 'inventory.valuation', 'inventory.return', 'accounting.post', 'accounting.create', 'purchase_orders.view', 'purchase_orders.receive']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        AccountingPeriod::factory()->for($company)->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $accounts = [
            'inventory_asset' => Account::factory()->for($company)->create(['code' => '1200', 'name' => 'Inventory Asset', 'created_by' => $user->id]),
            'cogs' => Account::factory()->for($company)->expense('cost_of_sales')->create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'created_by' => $user->id]),
            'inventory_adjustment' => Account::factory()->for($company)->expense()->create(['code' => '5050', 'name' => 'Inventory Adjustment', 'created_by' => $user->id]),
            'accounts_payable' => Account::factory()->for($company)->liability()->create(['code' => '2010', 'name' => 'Accounts Payable', 'created_by' => $user->id]),
            'accounts_receivable' => Account::factory()->for($company)->create(['code' => '1100', 'name' => 'Accounts Receivable', 'created_by' => $user->id]),
            'sales_revenue' => Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Sales Revenue', 'created_by' => $user->id]),
            'sales_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2020', 'name' => 'Sales Tax Payable', 'created_by' => $user->id]),
        ];
        foreach ($accounts as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $item = InventoryItem::factory()->for($company)->create([
            'created_by' => $user->id, 'inventory_asset_account_id' => $accounts['inventory_asset']->id,
            'cogs_account_id' => $accounts['cogs']->id, 'sales_account_id' => $accounts['sales_revenue']->id,
            'inventory_adjustment_account_id' => $accounts['inventory_adjustment']->id,
        ]);
        $warehouse = Warehouse::factory()->for($company)->default()->create(['created_by' => $user->id]);
        $customer = Customer::factory()->for($company)->create(['created_by' => $user->id]);
        $supplier = Supplier::factory()->for($company)->create([
            'created_by' => $user->id,
            'default_expense_account_id' => $accounts['inventory_adjustment']->id,
            'default_payable_account_id' => $accounts['accounts_payable']->id,
        ]);

        return compact('user', 'company', 'customer', 'supplier', 'item', 'warehouse', 'accounts');
    }
}
