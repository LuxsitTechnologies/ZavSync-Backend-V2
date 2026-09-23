<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create(['name' => 'Finance Administrator', 'email' => 'finance@example.com']);
        $company = Company::factory()->create(['name' => 'ZavSync Demo Company', 'slug' => 'zavsync-demo']);
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Finance Administrator', 'is_system' => true]);
        $permissions = collect([
            'accounting.view', 'accounting.create', 'accounting.edit', 'accounting.post', 'accounting.periods.manage',
            'suppliers.view', 'suppliers.manage', 'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
            'purchase_orders.approve', 'purchase_orders.cancel', 'purchase_orders.receive', 'supplier_bills.view',
            'supplier_bills.manage', 'supplier_bills.post', 'supplier_payments.create', 'payables.view',
            'inventory.view', 'inventory.manage', 'inventory.adjust', 'inventory.transfer', 'inventory.valuation', 'inventory.return',
            'banking.view', 'banking.manage', 'banking.import', 'banking.reconcile', 'banking.post', 'banking.transfer',
            'banking.settlements', 'banking.cashflow',
            'budget.view', 'budget.manage', 'budget.submit', 'budget.approve', 'forecast.view', 'forecast.manage',
            'accounting.close.view', 'accounting.period.close', 'accounting.period.reopen', 'accounting.year.close', 'accounting.year.reopen',
        ])->map(fn (string $name) => Permission::query()->create(['name' => $name]));
        $role->permissions()->attach($permissions);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
        $fiscalYear = FiscalYear::factory()->for($company)->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => $company->currency, 'created_by' => $user->id]);
        AccountingPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id]);
        Account::factory()->for($company)->create(['code' => '1000', 'name' => 'Current Assets', 'created_by' => $user->id]);
        $cash = Account::factory()->for($company)->create(['code' => '1010', 'name' => 'Cash in Hand', 'is_system' => true, 'created_by' => $user->id]);
        $bank = Account::factory()->for($company)->create(['code' => '1020', 'name' => 'Bank Account', 'is_system' => true, 'created_by' => $user->id]);
        $receivable = Account::factory()->for($company)->create(['code' => '1100', 'name' => 'Accounts Receivable', 'is_system' => true, 'created_by' => $user->id]);
        $salesTax = Account::factory()->for($company)->liability()->create(['code' => '2020', 'name' => 'Sales Tax Payable', 'is_system' => true, 'created_by' => $user->id]);
        Account::factory()->for($company)->equity()->create(['code' => '3000', 'name' => 'Owner Equity', 'created_by' => $user->id]);
        $retainedEarnings = Account::factory()->for($company)->equity()->create(['code' => '3100', 'name' => 'Retained Earnings', 'is_system' => true, 'created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Service Revenue', 'created_by' => $user->id]);
        $payable = Account::factory()->for($company)->liability()->create(['code' => '2010', 'name' => 'Accounts Payable', 'is_system' => true, 'created_by' => $user->id]);
        $purchaseTax = Account::factory()->for($company)->create(['code' => '1210', 'name' => 'Purchase Tax Recoverable', 'is_system' => true, 'created_by' => $user->id]);
        $withholding = Account::factory()->for($company)->liability()->create(['code' => '2050', 'name' => 'Withholding Tax Payable', 'is_system' => true, 'created_by' => $user->id]);
        $expense = Account::factory()->for($company)->expense()->create(['code' => '6000', 'name' => 'Operating Expenses', 'created_by' => $user->id]);
        $inventoryAsset = Account::factory()->for($company)->create(['code' => '1200', 'name' => 'Inventory Asset', 'is_system' => true, 'created_by' => $user->id]);
        $cogs = Account::factory()->for($company)->expense('cost_of_sales')->create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'is_system' => true, 'created_by' => $user->id]);
        $inventoryAdjustment = Account::factory()->for($company)->expense()->create(['code' => '5050', 'name' => 'Inventory Adjustment / Return Clearing', 'created_by' => $user->id]);
        $bankCharges = Account::factory()->for($company)->expense()->create(['code' => '6100', 'name' => 'Bank Charges', 'created_by' => $user->id]);
        $interestIncome = Account::factory()->for($company)->revenue()->create(['code' => '4100', 'name' => 'Interest Income', 'subtype' => 'other_income', 'created_by' => $user->id]);
        $gatewayClearing = Account::factory()->for($company)->create(['code' => '1150', 'name' => 'Gateway Clearing', 'created_by' => $user->id]);
        $gatewayFees = Account::factory()->for($company)->expense()->create(['code' => '6110', 'name' => 'Gateway Fees', 'created_by' => $user->id]);
        $cashOverShort = Account::factory()->for($company)->expense()->create(['code' => '6120', 'name' => 'Cash Over / Short', 'created_by' => $user->id]);
        foreach (['accounts_receivable' => $receivable, 'sales_revenue' => $revenue, 'sales_tax_payable' => $salesTax, 'bank' => $bank, 'cash' => $cash, 'accounts_payable' => $payable, 'purchase_expense' => $expense, 'purchase_tax_recoverable' => $purchaseTax, 'withholding_tax_payable' => $withholding, 'inventory_asset' => $inventoryAsset, 'cogs' => $cogs, 'inventory_adjustment' => $inventoryAdjustment, 'bank_charges' => $bankCharges, 'interest_income' => $interestIncome, 'gateway_clearing' => $gatewayClearing, 'gateway_fees' => $gatewayFees, 'cash_over_short' => $cashOverShort, 'retained_earnings' => $retainedEarnings] as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $customer = Customer::factory()->for($company)->create(['sequence' => 1, 'code' => 'CUS-0001', 'name' => 'Demo Customer', 'created_by' => $user->id]);
        $invoice = Invoice::factory()->for($company)->for($customer)->create(['sequence' => 1, 'invoice_number' => 'INV-2026-0001', 'created_by' => $user->id]);
        InvoiceLine::factory()->for($invoice)->create();
        Supplier::factory()->for($company)->create(['sequence' => 1, 'code' => 'SUP-0001', 'name' => 'Demo Supplier', 'default_expense_account_id' => $expense->id, 'default_payable_account_id' => $payable->id, 'created_by' => $user->id]);
        Warehouse::factory()->for($company)->default()->create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'created_by' => $user->id]);
        InventoryItem::factory()->for($company)->create([
            'sku' => 'DEMO-ITEM', 'name' => 'Demo Inventory Item', 'created_by' => $user->id,
            'inventory_asset_account_id' => $inventoryAsset->id, 'cogs_account_id' => $cogs->id,
            'sales_account_id' => $revenue->id, 'inventory_adjustment_account_id' => $inventoryAdjustment->id,
        ]);
        FinancialAccount::factory()->for($company)->create(['name' => 'Primary Bank', 'type' => 'bank', 'gl_account_id' => $bank->id, 'is_default' => true, 'created_by' => $user->id]);
        FinancialAccount::factory()->for($company)->create(['name' => 'Cash in Hand', 'type' => 'cash', 'bank_name' => null, 'gl_account_id' => $cash->id, 'created_by' => $user->id]);
    }
}
