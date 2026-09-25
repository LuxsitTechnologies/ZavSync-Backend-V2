<?php

namespace Tests;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\Customer;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeePayrollComponent;
use App\Models\EmployeePayrollProfile;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\OutreachSequence;
use App\Models\OutreachSequenceStep;
use App\Models\PayrollComponent;
use App\Models\PayrollPeriod;
use App\Models\PayrollStatutoryRule;
use App\Models\Permission;
use App\Models\PlatformModule;
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

    /** @return array{user:User,company:Company,customer:Customer,supplier:Supplier,accounts:array<string,Account>,bank:FinancialAccount,cash:FinancialAccount} */
    protected function stage6BankingContext(array $permissions = ['banking.view', 'banking.manage', 'banking.import', 'banking.reconcile', 'banking.post', 'banking.transfer', 'banking.settlements', 'banking.cashflow']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        AccountingPeriod::factory()->for($company)->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $accounts = [
            'bank' => Account::factory()->for($company)->create(['code' => '1020', 'name' => 'Bank', 'created_by' => $user->id]),
            'cash' => Account::factory()->for($company)->create(['code' => '1010', 'name' => 'Cash', 'created_by' => $user->id]),
            'accounts_receivable' => Account::factory()->for($company)->create(['code' => '1100', 'name' => 'Accounts Receivable', 'created_by' => $user->id]),
            'accounts_payable' => Account::factory()->for($company)->liability()->create(['code' => '2010', 'name' => 'Accounts Payable', 'created_by' => $user->id]),
            'bank_charges' => Account::factory()->for($company)->expense()->create(['code' => '6100', 'name' => 'Bank Charges', 'created_by' => $user->id]),
            'interest_income' => Account::factory()->for($company)->revenue()->create(['code' => '4100', 'name' => 'Interest Income', 'created_by' => $user->id]),
            'gateway_clearing' => Account::factory()->for($company)->create(['code' => '1150', 'name' => 'Gateway Clearing', 'created_by' => $user->id]),
            'gateway_fees' => Account::factory()->for($company)->expense()->create(['code' => '6110', 'name' => 'Gateway Fees', 'created_by' => $user->id]),
            'purchase_expense' => Account::factory()->for($company)->expense()->create(['code' => '6000', 'name' => 'Purchases', 'created_by' => $user->id]),
            'sales_revenue' => Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Revenue', 'created_by' => $user->id]),
            'sales_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2020', 'name' => 'Sales Tax', 'created_by' => $user->id]),
        ];
        foreach ($accounts as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $bank = FinancialAccount::factory()->for($company)->create(['name' => 'Primary Bank', 'gl_account_id' => $accounts['bank']->id, 'is_default' => true, 'created_by' => $user->id]);
        $cash = FinancialAccount::factory()->for($company)->create(['name' => 'Cash', 'type' => 'cash', 'bank_name' => null, 'gl_account_id' => $accounts['cash']->id, 'created_by' => $user->id]);
        $customer = Customer::factory()->for($company)->create(['created_by' => $user->id]);
        $supplier = Supplier::factory()->for($company)->create(['created_by' => $user->id, 'default_expense_account_id' => $accounts['purchase_expense']->id, 'default_payable_account_id' => $accounts['accounts_payable']->id]);

        return compact('user', 'company', 'customer', 'supplier', 'accounts', 'bank', 'cash');
    }

    /** @return array{user:User,company:Company,fiscalYear:FiscalYear,periods:array<int,AccountingPeriod>,accounts:array<string,Account>} */
    protected function stage7PlanningContext(array $permissions = ['accounting.view', 'accounting.create', 'accounting.post', 'accounting.close.view', 'accounting.period.close', 'accounting.period.reopen', 'accounting.year.close', 'accounting.year.reopen', 'budget.view', 'budget.manage', 'budget.submit', 'budget.approve', 'forecast.view', 'forecast.manage']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        $fiscalYear = FiscalYear::factory()->for($company)->create(['name' => 'FY 2027 Q1', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31', 'currency' => $company->currency, 'created_by' => $user->id]);
        $periods = [
            AccountingPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id, 'name' => 'January 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-01-31']),
            AccountingPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id, 'name' => 'February 2027', 'start_date' => '2027-02-01', 'end_date' => '2027-02-28']),
            AccountingPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id, 'name' => 'March 2027', 'start_date' => '2027-03-01', 'end_date' => '2027-03-31']),
        ];
        $accounts = [
            'cash' => Account::factory()->for($company)->create(['code' => '1010', 'name' => 'Cash', 'created_by' => $user->id]),
            'retained_earnings' => Account::factory()->for($company)->equity()->create(['code' => '3100', 'name' => 'Retained Earnings', 'created_by' => $user->id]),
            'revenue' => Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Revenue', 'created_by' => $user->id]),
            'other_income' => Account::factory()->for($company)->revenue()->create(['code' => '4100', 'name' => 'Other Income', 'subtype' => 'other_income', 'created_by' => $user->id]),
            'cogs' => Account::factory()->for($company)->expense('cost_of_sales')->create(['code' => '5000', 'name' => 'COGS', 'created_by' => $user->id]),
            'expense' => Account::factory()->for($company)->expense()->create(['code' => '6000', 'name' => 'Operating Expense', 'created_by' => $user->id]),
            'other_expense' => Account::factory()->for($company)->expense('other_expense')->create(['code' => '7000', 'name' => 'Other Expense', 'created_by' => $user->id]),
        ];
        AccountMapping::query()->create(['company_id' => $company->id, 'key' => 'retained_earnings', 'account_id' => $accounts['retained_earnings']->id, 'updated_by' => $user->id]);

        return compact('user', 'company', 'fiscalYear', 'periods', 'accounts');
    }

    /** @return array<string, mixed> */
    protected function stage8PayrollContext(array $permissions = ['payroll.view', 'payroll.manage', 'payroll.calculate', 'payroll.review', 'payroll.approve', 'payroll.post', 'payroll.pay', 'payroll.settle-liabilities', 'payroll.reports', 'payroll.configure', 'accounting.close.view', 'accounting.period.close', 'banking.view', 'banking.reconcile']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        $fiscalYear = FiscalYear::factory()->for($company)->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => 'PKR', 'created_by' => $user->id]);
        $accountingPeriod = AccountingPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id, 'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'open']);
        $accounts = [
            'bank' => Account::factory()->for($company)->create(['code' => '1020', 'name' => 'Bank', 'created_by' => $user->id]),
            'salary_expense' => Account::factory()->for($company)->expense()->create(['code' => '6200', 'name' => 'Salary Expense', 'created_by' => $user->id]),
            'payroll_net_payable' => Account::factory()->for($company)->liability()->create(['code' => '2100', 'name' => 'Net Payable', 'created_by' => $user->id]),
            'payroll_tax_payable' => Account::factory()->for($company)->liability()->create(['code' => '2110', 'name' => 'Tax Payable', 'created_by' => $user->id]),
            'payroll_employee_contribution_payable' => Account::factory()->for($company)->liability()->create(['code' => '2120', 'name' => 'Employee Contribution Payable', 'created_by' => $user->id]),
            'payroll_employer_contribution_expense' => Account::factory()->for($company)->expense()->create(['code' => '6210', 'name' => 'Employer Contribution Expense', 'created_by' => $user->id]),
            'payroll_employer_contribution_payable' => Account::factory()->for($company)->liability()->create(['code' => '2130', 'name' => 'Employer Contribution Payable', 'created_by' => $user->id]),
            'payroll_other_deduction_payable' => Account::factory()->for($company)->liability()->create(['code' => '2140', 'name' => 'Other Deduction Payable', 'created_by' => $user->id]),
        ];
        foreach ($accounts as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $bank = FinancialAccount::factory()->for($company)->create(['name' => 'Payroll Bank', 'gl_account_id' => $accounts['bank']->id, 'currency' => 'PKR', 'created_by' => $user->id]);
        $employee = Employee::factory()->for($company)->create(['employee_code' => 'EMP-0001', 'full_name' => 'Payroll Employee', 'joining_date' => '2026-01-01', 'created_by' => $user->id]);
        $components = [
            'allowance' => PayrollComponent::factory()->for($company)->create(['code' => 'ALW', 'name' => 'Allowance', 'type' => 'EARNINGS', 'fixed_amount' => 100_000, 'is_taxable' => true, 'gl_account_id' => $accounts['salary_expense']->id, 'created_by' => $user->id]),
            'deduction' => PayrollComponent::factory()->for($company)->deduction()->create(['code' => 'DED', 'name' => 'Deduction', 'fixed_amount' => 50_000, 'liability_account_id' => $accounts['payroll_other_deduction_payable']->id, 'created_by' => $user->id]),
            'employee_contribution' => PayrollComponent::factory()->for($company)->create(['code' => 'EMP-CONT', 'name' => 'Employee Contribution', 'type' => 'EMPLOYEE_CONTRIBUTIONS', 'fixed_amount' => 30_000, 'is_taxable' => false, 'liability_account_id' => $accounts['payroll_employee_contribution_payable']->id, 'created_by' => $user->id]),
            'employer_contribution' => PayrollComponent::factory()->for($company)->create(['code' => 'ER-CONT', 'name' => 'Employer Contribution', 'type' => 'EMPLOYER_CONTRIBUTIONS', 'fixed_amount' => 40_000, 'is_taxable' => false, 'gl_account_id' => $accounts['payroll_employer_contribution_expense']->id, 'liability_account_id' => $accounts['payroll_employer_contribution_payable']->id, 'created_by' => $user->id]),
            'tax' => PayrollComponent::factory()->for($company)->create(['code' => 'TAX', 'name' => 'Income Tax', 'type' => 'TAX', 'calculation_method' => 'statutory', 'fixed_amount' => null, 'is_taxable' => false, 'liability_account_id' => $accounts['payroll_tax_payable']->id, 'created_by' => $user->id]),
        ];
        $profile = EmployeePayrollProfile::factory()->for($company)->for($employee)->create(['base_salary' => 1_000_000, 'currency' => 'PKR', 'effective_from' => '2026-01-01', 'payment_financial_account_id' => $bank->id, 'created_by' => $user->id]);
        foreach ($components as $component) {
            EmployeePayrollComponent::factory()->for($company)->for($profile, 'profile')->for($component, 'component')->create();
        }
        $rule = PayrollStatutoryRule::factory()->for($company)->for($components['tax'], 'component')->create(['version' => '2026-test', 'effective_from' => '2026-01-01', 'threshold_from' => 0, 'rate_bps' => 1000, 'created_by' => $user->id]);
        $payrollPeriod = PayrollPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id, 'accounting_period_id' => $accountingPeriod->id, 'name' => 'September 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'pay_date' => '2026-09-30', 'created_by' => $user->id]);

        return compact('user', 'company', 'fiscalYear', 'accountingPeriod', 'accounts', 'bank', 'employee', 'components', 'profile', 'rule', 'payrollPeriod');
    }

    /** @return array<string, mixed> */
    protected function stage9CrmContext(array $permissions = ['crm.view', 'crm.accounts.manage', 'crm.contacts.manage', 'crm.leads.manage', 'crm.deals.manage', 'crm.activities.manage', 'crm.pipelines.manage', 'crm.import', 'crm.scoring.manage', 'crm.customer.convert', 'crm.reports.view']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        $pipeline = CrmPipeline::factory()->for($company)->create(['name' => 'Sales', 'is_default' => true, 'created_by' => $user->id]);
        $stages = [
            'open' => CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->create(['name' => 'Qualification', 'position' => 1, 'probability_bps' => 2500]),
            'won' => CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->won()->create(['name' => 'Won', 'position' => 2]),
            'lost' => CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->lost()->create(['name' => 'Lost', 'position' => 3]),
        ];
        $account = CrmAccount::factory()->for($company)->create(['owner_id' => $user->id, 'created_by' => $user->id]);
        $contact = CrmContact::factory()->for($company)->for($account, 'account')->create(['owner_id' => $user->id, 'is_primary' => true, 'created_by' => $user->id]);
        $lead = CrmLead::factory()->for($company)->qualified()->create(['account_id' => $account->id, 'contact_id' => $contact->id, 'owner_id' => $user->id, 'created_by' => $user->id]);

        return compact('user', 'company', 'pipeline', 'stages', 'account', 'contact', 'lead');
    }

    /** @return array<string, mixed> */
    protected function stage11OutreachContext(array $permissions = ['outreach.view', 'outreach.manage', 'outreach.send', 'outreach.templates.manage', 'outreach.sequences.manage', 'outreach.providers.manage', 'outreach.suppressions.manage', 'outreach.reports.view']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
        PlatformModule::query()->firstOrCreate(['key' => 'outreach'], ['name' => 'Outreach']);
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'outreach', 'is_enabled' => true, 'limits' => ['daily_messages' => 100, 'active_sequences' => 10, 'enrolled_recipients' => 1000, 'sending_identities' => 5], 'updated_by' => $user->id]);
        CompanySetting::factory()->for($company)->create(['legal_name' => $company->name, 'timezone' => 'Asia/Karachi', 'outreach_physical_address' => 'Test Company, Karachi, Pakistan', 'outreach_footer' => 'Test outreach footer', 'updated_by' => $user->id]);
        $connection = EmailProviderConnection::factory()->for($company)->create(['name' => 'Test SMTP', 'status' => 'CONNECTED', 'created_by' => $user->id]);
        $identity = EmailSendingIdentity::factory()->for($company)->for($connection, 'connection')->create(['from_email' => 'sender@example.test', 'from_name' => 'Test Sender', 'is_default' => true, 'verification_status' => 'VERIFIED', 'created_by' => $user->id]);
        $template = EmailTemplate::factory()->for($company)->create(['name' => 'Introduction', 'subject' => 'Hello {{contact.first_name}}', 'body_text' => 'Hello {{contact.first_name}} from {{company.name}}.', 'body_html' => '<p>Hello {{contact.first_name}} from {{company.name}}.</p>', 'allowed_variables' => ['contact.first_name', 'company.name'], 'created_by' => $user->id]);
        $sequence = OutreachSequence::factory()->for($company)->for($identity, 'sendingIdentity')->create(['name' => 'Introduction sequence', 'status' => 'DRAFT', 'created_by' => $user->id]);
        OutreachSequenceStep::factory()->for($company)->for($sequence, 'sequence')->for($template, 'template')->create(['position' => 1, 'type' => 'EMAIL', 'subject' => $template->subject, 'body_text' => $template->body_text, 'wait_minutes' => 0]);
        $account = CrmAccount::factory()->for($company)->create(['owner_id' => $user->id, 'created_by' => $user->id]);
        $contact = CrmContact::factory()->for($company)->for($account, 'account')->create(['first_name' => 'Ayesha', 'last_name' => 'Khan', 'email' => 'ayesha@example.test', 'status' => 'ACTIVE', 'owner_id' => $user->id, 'created_by' => $user->id]);
        $lead = CrmLead::factory()->for($company)->create(['first_name' => 'Bilal', 'last_name' => 'Ali', 'email' => 'bilal@example.test', 'status' => 'QUALIFIED', 'owner_id' => $user->id, 'created_by' => $user->id]);

        return compact('user', 'company', 'connection', 'identity', 'template', 'sequence', 'account', 'contact', 'lead');
    }
}
