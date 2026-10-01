<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AiConversation;
use App\Models\AiEvaluationCase;
use App\Models\AiProviderConfiguration;
use App\Models\AiProviderReconciliation;
use App\Models\AnomalyResult;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\CrmAccount;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmScoreEvent;
use App\Models\CrmScoreRule;
use App\Models\CrmTag;
use App\Models\Customer;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeePayrollComponent;
use App\Models\EmployeePayrollProfile;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrReferenceValue;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\IntelligenceBriefing;
use App\Models\IntelligenceForecast;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\KnowledgeSource;
use App\Models\OperationalPrioritySignal;
use App\Models\OutreachSequence;
use App\Models\OutreachSequenceStep;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Models\PayrollComponent;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\Subscription;
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
        $user = User::factory()->create(['name' => 'Finance Administrator', 'email' => 'finance@example.com', 'is_platform_admin' => true]);
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
            'payroll.view', 'payroll.manage', 'payroll.calculate', 'payroll.review', 'payroll.approve', 'payroll.post',
            'payroll.pay', 'payroll.settle-liabilities', 'payroll.reports', 'payroll.configure',
            'crm.view', 'crm.accounts.manage', 'crm.contacts.manage', 'crm.leads.manage', 'crm.deals.manage',
            'crm.activities.manage', 'crm.pipelines.manage', 'crm.import', 'crm.scoring.manage',
            'crm.customer.convert', 'crm.reports.view',
            'outreach.view', 'outreach.manage', 'outreach.send', 'outreach.templates.manage',
            'outreach.sequences.manage', 'outreach.providers.manage', 'outreach.suppressions.manage', 'outreach.reports.view',
            'platform.users.view', 'platform.users.manage', 'platform.invitations.manage',
            'platform.roles.view', 'platform.roles.manage', 'platform.settings.view', 'platform.settings.manage', 'platform.settings.high-risk',
            'platform.subscription.view', 'platform.subscription.manage', 'platform.plans.manage',
            'platform.documents.view', 'platform.documents.manage', 'platform.audit.view', 'platform.security.view', 'platform.security.manage',
            'platform.jobs.view', 'platform.jobs.manage', 'platform.exports.view', 'platform.exports.manage',
            'fbr.configuration.view', 'fbr.configuration.manage', 'migration.view', 'migration.manage',
            'pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit',
            'ai.copilot.use', 'ai.knowledge.view', 'ai.knowledge.manage', 'ai.tools.use',
            'ai.actions.propose', 'ai.actions.review', 'ai.actions.approve', 'ai.actions.execute',
            'ai.providers.view', 'ai.providers.manage', 'ai.usage.view', 'ai.evaluations.view', 'ai.evaluations.manage',
            'intelligence.view', 'intelligence.manage', 'intelligence.anomalies.view', 'intelligence.forecasts.view',
            'intelligence.scenarios.manage', 'intelligence.briefings.view', 'intelligence.schedule.manage',
            'intelligence.calendar.manage', 'intelligence.observability.view', 'intelligence.evaluations.manage',
        ])->map(fn (string $name) => Permission::query()->create(['name' => $name]));
        $role->permissions()->attach($permissions);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
        CompanySetting::query()->create([
            'company_id' => $company->id, 'legal_name' => $company->name, 'trading_name' => 'ZavSync Demo',
            'email' => 'finance@example.com', 'country_code' => 'PK', 'timezone' => 'Asia/Karachi', 'base_currency' => 'PKR', 'updated_by' => $user->id,
            'outreach_physical_address' => 'ZavSync Demo Company, Karachi, Pakistan', 'outreach_footer' => 'You are receiving this email from ZavSync Demo Company.',
        ]);
        foreach ([
            ['category' => 'PROVINCE', 'code' => 'SINDH', 'label' => 'Sindh'],
            ['category' => 'PROVINCE', 'code' => 'PUNJAB', 'label' => 'Punjab'],
            ['category' => 'PROVINCE', 'code' => 'KPK', 'label' => 'Khyber Pakhtunkhwa'],
            ['category' => 'PROVINCE', 'code' => 'BALOCHISTAN', 'label' => 'Balochistan'],
            ['category' => 'DOCUMENT_TYPE', 'code' => 'SALE_INVOICE', 'label' => 'Sale Invoice'],
            ['category' => 'UOM', 'code' => 'UNIT', 'label' => 'Unit'],
            ['category' => 'UOM', 'code' => 'KG', 'label' => 'Kilogram'],
            ['category' => 'SALE_TYPE', 'code' => 'STANDARD', 'label' => 'Standardized Goods'],
            ['category' => 'RATE', 'code' => '18', 'label' => '18%'],
            ['category' => 'HS_CODE', 'code' => '9983.0000', 'label' => 'Professional services fixture'],
            ['category' => 'SRO_SCHEDULE', 'code' => 'NOT_APPLICABLE', 'label' => 'Not applicable'],
            ['category' => 'SRO_ITEM', 'code' => 'NOT_APPLICABLE', 'label' => 'Not applicable'],
            ['category' => 'SCENARIO', 'code' => 'NOT_CERTIFIED', 'label' => 'Requires staging certification'],
        ] as $reference) {
            FbrReferenceValue::query()->create([...$reference, 'source' => 'LOCAL_FIXTURE', 'source_version' => '2026-01', 'is_active' => true]);
        }
        FbrCompanyConfiguration::query()->create([
            'company_id' => $company->id, 'seller_tax_identifier' => '1234567', 'seller_business_name' => $company->name,
            'seller_province' => 'SINDH', 'seller_address' => 'Karachi, Pakistan', 'environment' => 'SANDBOX',
            'credential' => null, 'connection_state' => 'NOT_VERIFIED', 'updated_by' => $user->id,
        ]);
        $modules = collect([
            'accounting' => 'Accounting', 'invoicing' => 'Invoicing / FBR', 'receivables' => 'Receivables',
            'procurement' => 'Procurement', 'payables' => 'Payables', 'inventory' => 'Inventory', 'banking' => 'Banking',
            'budgeting' => 'Budgeting', 'payroll' => 'Payroll', 'crm' => 'CRM', 'outreach' => 'Outreach', 'ai' => 'AI Assistant', 'analytics' => 'Analytics',
        ])->map(fn (string $name, string $key) => PlatformModule::query()->create(['key' => $key, 'name' => $name]));
        $planDefinitions = [
            'starter' => ['Starter', 250_000, ['accounting', 'invoicing', 'receivables'], ['users' => 5, 'employees' => 10, 'monthly_invoices' => 100, 'document_storage_mb' => 500, 'daily_messages' => 0, 'active_sequences' => 0, 'enrolled_recipients' => 0, 'sending_identities' => 0, 'daily_ai_requests' => 0, 'monthly_ai_tokens' => 0, 'monthly_ai_cost_minor' => 0, 'knowledge_sources' => 0, 'knowledge_chunks' => 0]],
            'growth' => ['Growth', 750_000, ['accounting', 'invoicing', 'receivables', 'procurement', 'payables', 'inventory', 'crm'], ['users' => 20, 'employees' => 50, 'monthly_invoices' => 1000, 'document_storage_mb' => 5000, 'daily_messages' => 0, 'active_sequences' => 0, 'enrolled_recipients' => 0, 'sending_identities' => 0, 'daily_ai_requests' => 0, 'monthly_ai_tokens' => 0, 'monthly_ai_cost_minor' => 0, 'knowledge_sources' => 0, 'knowledge_chunks' => 0]],
            'professional' => ['Professional', 1_500_000, ['accounting', 'invoicing', 'receivables', 'procurement', 'payables', 'inventory', 'banking', 'budgeting', 'payroll', 'crm', 'outreach', 'ai'], ['users' => 100, 'employees' => 500, 'monthly_invoices' => -1, 'document_storage_mb' => 25_000, 'daily_messages' => 5000, 'active_sequences' => 50, 'enrolled_recipients' => 100000, 'sending_identities' => 20, 'daily_ai_requests' => 500, 'monthly_ai_tokens' => 5_000_000, 'monthly_ai_cost_minor' => 250_000, 'knowledge_sources' => 500, 'knowledge_chunks' => 100_000]],
            'enterprise' => ['Enterprise', null, $modules->keys()->all(), ['users' => -1, 'employees' => -1, 'monthly_invoices' => -1, 'document_storage_mb' => -1, 'daily_messages' => -1, 'active_sequences' => -1, 'enrolled_recipients' => -1, 'sending_identities' => -1, 'daily_ai_requests' => -1, 'monthly_ai_tokens' => -1, 'monthly_ai_cost_minor' => -1, 'knowledge_sources' => -1, 'knowledge_chunks' => -1]],
        ];
        $plans = collect($planDefinitions)->map(function (array $definition, string $code): Plan {
            [$name, $price, $moduleKeys, $limits] = $definition;
            $plan = Plan::query()->create(['code' => $code, 'name' => $name, 'price_minor' => $price, 'currency' => 'PKR', 'billing_interval' => 'monthly', 'usage_limits' => $limits, 'features' => []]);
            $plan->modules()->attach($moduleKeys, ['is_enabled' => true]);

            return $plan;
        });
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plans['professional']->id, 'status' => 'ACTIVE', 'billing_interval' => 'monthly', 'starts_at' => now(), 'renews_at' => now()->addMonth()]);
        PlatformNotification::query()->create(['company_id' => $company->id, 'recipient_id' => $user->id, 'type' => 'security.login', 'channel' => 'IN_APP', 'title' => 'Platform administration ready', 'message' => 'Review company settings, roles, and production-readiness checks.', 'delivery_state' => 'DELIVERED', 'delivered_at' => now()]);
        $fiscalYear = FiscalYear::factory()->for($company)->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => $company->currency, 'created_by' => $user->id]);
        $accountingPeriod = AccountingPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id]);
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
        $salaryExpense = Account::factory()->for($company)->expense()->create(['code' => '6200', 'name' => 'Salary Expense', 'created_by' => $user->id]);
        $netPayable = Account::factory()->for($company)->liability()->create(['code' => '2100', 'name' => 'Employee Net Pay Payable', 'created_by' => $user->id]);
        $payrollTaxPayable = Account::factory()->for($company)->liability()->create(['code' => '2110', 'name' => 'Payroll Tax Payable', 'created_by' => $user->id]);
        $employeeContributionPayable = Account::factory()->for($company)->liability()->create(['code' => '2120', 'name' => 'Employee Contribution Payable', 'created_by' => $user->id]);
        $employerContributionExpense = Account::factory()->for($company)->expense()->create(['code' => '6210', 'name' => 'Employer Contribution Expense', 'created_by' => $user->id]);
        $employerContributionPayable = Account::factory()->for($company)->liability()->create(['code' => '2130', 'name' => 'Employer Contribution Payable', 'created_by' => $user->id]);
        $otherDeductionPayable = Account::factory()->for($company)->liability()->create(['code' => '2140', 'name' => 'Other Payroll Deduction Payable', 'created_by' => $user->id]);
        foreach (['accounts_receivable' => $receivable, 'sales_revenue' => $revenue, 'sales_tax_payable' => $salesTax, 'bank' => $bank, 'cash' => $cash, 'accounts_payable' => $payable, 'purchase_expense' => $expense, 'purchase_tax_recoverable' => $purchaseTax, 'withholding_tax_payable' => $withholding, 'inventory_asset' => $inventoryAsset, 'cogs' => $cogs, 'inventory_adjustment' => $inventoryAdjustment, 'bank_charges' => $bankCharges, 'interest_income' => $interestIncome, 'gateway_clearing' => $gatewayClearing, 'gateway_fees' => $gatewayFees, 'cash_over_short' => $cashOverShort, 'retained_earnings' => $retainedEarnings, 'salary_expense' => $salaryExpense, 'payroll_net_payable' => $netPayable, 'payroll_tax_payable' => $payrollTaxPayable, 'payroll_employee_contribution_payable' => $employeeContributionPayable, 'payroll_employer_contribution_expense' => $employerContributionExpense, 'payroll_employer_contribution_payable' => $employerContributionPayable, 'payroll_other_deduction_payable' => $otherDeductionPayable] as $key => $account) {
            AccountMapping::query()->create(['company_id' => $company->id, 'key' => $key, 'account_id' => $account->id, 'updated_by' => $user->id]);
        }
        $customer = Customer::factory()->for($company)->create(['sequence' => 1, 'code' => 'CUS-0001', 'name' => 'Demo Customer', 'created_by' => $user->id]);
        $invoice = Invoice::factory()->for($company)->for($customer)->create(['sequence' => 1, 'invoice_number' => 'INV-2026-0001', 'created_by' => $user->id]);
        InvoiceLine::factory()->for($invoice)->create();
        $pakistanInvoice = PakistanFbrInvoice::factory()->for($company)->create(['sequence' => 1, 'invoice_number' => 'PKF-00000001', 'created_by' => $user->id]);
        PakistanFbrInvoiceLine::factory()->for($pakistanInvoice, 'invoice')->create();
        Supplier::factory()->for($company)->create(['sequence' => 1, 'code' => 'SUP-0001', 'name' => 'Demo Supplier', 'default_expense_account_id' => $expense->id, 'default_payable_account_id' => $payable->id, 'created_by' => $user->id]);
        Warehouse::factory()->for($company)->default()->create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'created_by' => $user->id]);
        InventoryItem::factory()->for($company)->create([
            'sku' => 'DEMO-ITEM', 'name' => 'Demo Inventory Item', 'created_by' => $user->id,
            'inventory_asset_account_id' => $inventoryAsset->id, 'cogs_account_id' => $cogs->id,
            'sales_account_id' => $revenue->id, 'inventory_adjustment_account_id' => $inventoryAdjustment->id,
        ]);
        $primaryBank = FinancialAccount::factory()->for($company)->create(['name' => 'Primary Bank', 'type' => 'bank', 'gl_account_id' => $bank->id, 'is_default' => true, 'created_by' => $user->id]);
        FinancialAccount::factory()->for($company)->create(['name' => 'Cash in Hand', 'type' => 'cash', 'bank_name' => null, 'gl_account_id' => $cash->id, 'created_by' => $user->id]);
        $employee = Employee::factory()->for($company)->create(['employee_code' => 'EMP-0001', 'full_name' => 'Demo Payroll Employee', 'email' => 'employee@example.com', 'department' => 'Finance', 'designation' => 'Payroll Officer', 'joining_date' => '2026-01-01', 'created_by' => $user->id]);
        $transport = PayrollComponent::factory()->for($company)->create(['code' => 'EARN-TRANSPORT', 'name' => 'Transport Allowance', 'type' => 'EARNINGS', 'calculation_method' => 'fixed', 'fixed_amount' => 20_000_00, 'is_taxable' => true, 'gl_account_id' => $salaryExpense->id, 'created_by' => $user->id]);
        $profile = EmployeePayrollProfile::factory()->for($company)->for($employee)->create(['base_salary' => 300_000_00, 'currency' => 'PKR', 'effective_from' => '2026-01-01', 'payment_financial_account_id' => $primaryBank->id, 'created_by' => $user->id]);
        EmployeePayrollComponent::factory()->for($company)->for($profile, 'profile')->for($transport, 'component')->create(['fixed_amount' => 20_000_00]);
        PayrollPeriod::factory()->for($company)->create(['fiscal_year_id' => $fiscalYear->id, 'accounting_period_id' => $accountingPeriod->id, 'name' => 'September 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'pay_date' => '2026-09-30', 'created_by' => $user->id]);

        $pipeline = CrmPipeline::factory()->for($company)->create(['name' => 'Primary Sales Pipeline', 'description' => 'Default commercial opportunity lifecycle.', 'is_default' => true, 'created_by' => $user->id]);
        $qualification = CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->create(['name' => 'Qualification', 'position' => 1, 'probability_bps' => 2500]);
        $proposal = CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->create(['name' => 'Proposal', 'position' => 2, 'probability_bps' => 5000]);
        $negotiation = CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->create(['name' => 'Negotiation', 'position' => 3, 'probability_bps' => 7500]);
        $won = CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->won()->create(['name' => 'Won', 'position' => 4]);
        $lost = CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->lost()->create(['name' => 'Lost', 'position' => 5]);
        $partnerPipeline = CrmPipeline::factory()->for($company)->create(['name' => 'Partner Pipeline', 'description' => 'Channel and partner opportunities.', 'is_default' => false, 'created_by' => $user->id]);
        CrmPipelineStage::factory()->for($company)->for($partnerPipeline, 'pipeline')->create(['name' => 'Partner Review', 'position' => 1, 'probability_bps' => 3000]);
        CrmPipelineStage::factory()->for($company)->for($partnerPipeline, 'pipeline')->won()->create(['name' => 'Partner Won', 'position' => 2]);
        $crmAccount = CrmAccount::factory()->for($company)->create(['name' => 'Indus Digital Systems', 'industry' => 'Technology', 'owner_id' => $user->id, 'created_by' => $user->id]);
        $crmContact = CrmContact::factory()->for($company)->for($crmAccount, 'account')->create(['first_name' => 'Hira', 'last_name' => 'Iqbal', 'email' => 'hira@indus.example', 'owner_id' => $user->id, 'is_primary' => true, 'created_by' => $user->id]);
        $qualifiedLead = CrmLead::factory()->for($company)->qualified()->create(['account_id' => $crmAccount->id, 'contact_id' => $crmContact->id, 'first_name' => 'Hira', 'last_name' => 'Iqbal', 'company_name' => $crmAccount->name, 'email' => $crmContact->email, 'owner_id' => $user->id, 'score' => 80, 'created_by' => $user->id]);
        CrmLead::factory()->count(4)->for($company)->create(['owner_id' => $user->id, 'created_by' => $user->id]);
        $openDeal = CrmDeal::factory()->for($company)->for($crmAccount, 'account')->for($pipeline, 'pipeline')->for($proposal, 'stage')->create(['primary_contact_id' => $crmContact->id, 'lead_origin_id' => $qualifiedLead->id, 'owner_id' => $user->id, 'title' => 'ZavSync ERP rollout', 'amount' => 12_500_000, 'probability_bps' => 5000, 'status' => 'OPEN', 'created_by' => $user->id]);
        CrmDeal::factory()->for($company)->for($crmAccount, 'account')->for($pipeline, 'pipeline')->for($negotiation, 'stage')->create(['primary_contact_id' => $crmContact->id, 'owner_id' => $user->id, 'title' => 'Finance automation expansion', 'amount' => 8_000_000, 'probability_bps' => 7500, 'status' => 'OPEN', 'created_by' => $user->id]);
        $wonDeal = CrmDeal::factory()->for($company)->for($crmAccount, 'account')->for($pipeline, 'pipeline')->for($won, 'stage')->create(['primary_contact_id' => $crmContact->id, 'owner_id' => $user->id, 'title' => 'Analytics implementation', 'amount' => 5_000_000, 'probability_bps' => 10000, 'status' => 'WON', 'actual_close_date' => '2026-09-20', 'closed_at' => '2026-09-20 10:00:00', 'created_by' => $user->id]);
        CrmDeal::factory()->for($company)->for($crmAccount, 'account')->for($pipeline, 'pipeline')->for($lost, 'stage')->create(['primary_contact_id' => $crmContact->id, 'owner_id' => $user->id, 'title' => 'Legacy migration', 'amount' => 2_500_000, 'probability_bps' => 0, 'status' => 'LOST', 'loss_reason' => 'Timing', 'actual_close_date' => '2026-09-18', 'closed_at' => '2026-09-18 10:00:00', 'created_by' => $user->id]);
        $convertedLead = CrmLead::factory()->for($company)->create(['first_name' => 'Omar', 'last_name' => 'Shah', 'company_name' => $crmAccount->name, 'status' => 'CONVERTED', 'converted_at' => '2026-09-20 10:00:00', 'conversion_idempotency_key' => 'seed-conversion-1', 'converted_account_id' => $crmAccount->id, 'converted_contact_id' => $crmContact->id, 'converted_deal_id' => $wonDeal->id, 'owner_id' => $user->id, 'created_by' => $user->id]);
        CrmActivity::factory()->for($company)->for($qualifiedLead, 'activityable')->create(['owner_id' => $user->id, 'type' => 'CALL', 'subject' => 'Discovery call', 'created_by' => $user->id]);
        CrmActivity::factory()->completed()->for($company)->for($openDeal, 'activityable')->create(['owner_id' => $user->id, 'type' => 'MEETING', 'subject' => 'Proposal review', 'created_by' => $user->id]);
        CrmActivity::factory()->for($company)->for($qualifiedLead, 'activityable')->create(['owner_id' => $user->id, 'type' => 'TASK', 'subject' => 'Overdue follow-up', 'due_at' => '2026-09-01 09:00:00', 'status' => 'PENDING', 'created_by' => $user->id]);
        $vip = CrmTag::factory()->for($company)->create(['name' => 'VIP', 'normalized_name' => 'vip', 'created_by' => $user->id]);
        $crmAccount->tags()->attach($vip->id, ['company_id' => $company->id]);
        $emailRule = CrmScoreRule::factory()->for($company)->create(['name' => 'Has business email', 'target_type' => 'LEAD', 'field' => 'email', 'operator' => 'NOT_EMPTY', 'points' => 20, 'position' => 1, 'created_by' => $user->id]);
        CrmScoreRule::factory()->for($company)->create(['name' => 'Qualified lead', 'target_type' => 'LEAD', 'field' => 'status', 'operator' => 'EQUALS', 'comparison_value' => 'QUALIFIED', 'points' => 60, 'position' => 2, 'created_by' => $user->id]);
        CrmScoreEvent::factory()->for($company)->for($qualifiedLead, 'scoreable')->for($emailRule, 'rule')->create(['points' => 20, 'reason' => $emailRule->name, 'created_by' => $user->id]);
        $providerConnection = EmailProviderConnection::factory()->for($company)->create(['name' => 'Demo SMTP', 'status' => 'DISCONNECTED', 'last_verified_at' => null, 'created_by' => $user->id]);
        $sendingIdentity = EmailSendingIdentity::factory()->for($company)->for($providerConnection, 'connection')->create(['from_email' => 'outreach@zavsync.example', 'from_name' => 'ZavSync Demo', 'verification_status' => 'PENDING', 'verified_at' => null, 'is_default' => true, 'created_by' => $user->id]);
        $outreachTemplate = EmailTemplate::factory()->for($company)->create(['name' => 'CRM introduction', 'subject' => 'A better workflow for {{account.name}}', 'body_text' => "Hello {{contact.first_name}},\n\nI would like to share how {{company.name}} can help your team.\n\nRegards,\n{{sender.name}}", 'allowed_variables' => ['account.name', 'contact.first_name', 'company.name', 'sender.name'], 'created_by' => $user->id]);
        $outreachSequence = OutreachSequence::factory()->for($company)->for($sendingIdentity, 'sendingIdentity')->create(['name' => 'CRM introduction sequence', 'status' => 'DRAFT', 'created_by' => $user->id]);
        OutreachSequenceStep::factory()->for($company)->for($outreachSequence, 'sequence')->for($outreachTemplate, 'template')->create(['position' => 1, 'type' => 'EMAIL', 'subject' => $outreachTemplate->subject, 'body_text' => $outreachTemplate->body_text, 'wait_minutes' => 0]);
        OutreachSequenceStep::factory()->for($company)->for($outreachSequence, 'sequence')->create(['position' => 2, 'type' => 'WAIT', 'subject' => null, 'body_text' => null, 'wait_minutes' => 4320]);
        OutreachSequenceStep::factory()->for($company)->for($outreachSequence, 'sequence')->create(['position' => 3, 'type' => 'EMAIL', 'subject' => 'Following up with {{contact.first_name}}', 'body_text' => 'Hello {{contact.first_name}}, following up on my earlier note.', 'wait_minutes' => 0]);
        AiProviderConfiguration::query()->create(['company_id' => $company->id, 'provider' => 'openai', 'chat_model' => 'gpt-5-mini', 'embedding_model' => 'text-embedding-3-small', 'settings' => ['input_cost_per_million_minor' => 0, 'output_cost_per_million_minor' => 0, 'embedding_cost_per_million_minor' => 0], 'is_enabled' => false, 'updated_by' => $user->id]);
        KnowledgeSource::query()->create(['company_id' => $company->id, 'source_type' => 'NOTE', 'title' => 'Month-end close policy', 'content' => 'Review reconciliations, resolve exceptions, approve draft adjustments, and close the accounting period only after the readiness checks pass.', 'access_permission' => 'accounting.close.view', 'status' => 'PENDING', 'checksum_sha256' => hash('sha256', 'Review reconciliations, resolve exceptions, approve draft adjustments, and close the accounting period only after the readiness checks pass.'), 'version' => 1, 'chunk_count' => 0, 'created_by' => $user->id]);
        AiConversation::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'title' => 'Welcome to ZavSync Copilot']);
        AiEvaluationCase::query()->create(['company_id' => $company->id, 'created_by' => $user->id, 'name' => 'Close policy grounding', 'prompt' => 'Summarize our month-end close policy.', 'expected_citations' => ['Month-end close policy'], 'expected_tools' => [], 'forbidden_actions' => ['JOURNAL_POST'], 'is_active' => true]);
        OperationalPrioritySignal::factory()->for($company)->create(['source_module' => 'crm', 'category' => 'CRM', 'source_type' => 'company', 'source_id' => null, 'title' => 'Overdue CRM follow-up', 'description' => 'A seeded CRM follow-up requires attention.', 'fingerprint' => hash('sha256', 'seed-crm-priority'), 'explanation_metadata' => ['required_permission' => 'crm.view'], 'related_url' => '/crm/activities']);
        AnomalyResult::factory()->for($company)->create(['fingerprint' => hash('sha256', 'seed-expense-anomaly')]);
        IntelligenceForecast::factory()->for($company)->create(['fingerprint' => hash('sha256', 'seed-cash-forecast')]);
        IntelligenceBriefing::factory()->for($company)->create(['created_by' => $user->id, 'fingerprint' => hash('sha256', 'seed-today-briefing')]);
        AiProviderReconciliation::factory()->for($company)->create(['period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
    }
}
