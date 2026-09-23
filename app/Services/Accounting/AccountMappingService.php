<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountMapping;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccountMappingService
{
    /** @var array<string, array{label:string,description:string,required:bool,types:array<int,string>}> */
    public const DEFINITIONS = [
        'accounts_receivable' => ['label' => 'Accounts Receivable control', 'description' => 'Debited when a customer invoice is posted.', 'required' => true, 'types' => ['asset']],
        'sales_revenue' => ['label' => 'Default revenue account', 'description' => 'Credited with invoice revenue after line discounts.', 'required' => true, 'types' => ['revenue']],
        'sales_tax_payable' => ['label' => 'Output sales tax payable', 'description' => 'Credited with sales tax charged on invoices.', 'required' => true, 'types' => ['liability']],
        'other_tax_payable' => ['label' => 'Other output tax payable', 'description' => 'Credited with other invoice taxes.', 'required' => false, 'types' => ['liability']],
        'advance_tax_payable' => ['label' => 'Advance tax payable', 'description' => 'Credited with 236G/236H and similar advance tax collected.', 'required' => false, 'types' => ['liability']],
        'withholding_tax_receivable' => ['label' => 'Withholding tax receivable', 'description' => 'Debited with tax withheld by customers.', 'required' => false, 'types' => ['asset']],
        'bank' => ['label' => 'Default bank account', 'description' => 'Suggested account for customer receipts.', 'required' => true, 'types' => ['asset']],
        'cash' => ['label' => 'Cash account', 'description' => 'Suggested account for cash receipts.', 'required' => false, 'types' => ['asset']],
        'accounts_payable' => ['label' => 'Accounts Payable control', 'description' => 'Credited when a supplier bill is posted.', 'required' => true, 'types' => ['liability']],
        'purchase_expense' => ['label' => 'Default purchase expense', 'description' => 'Suggested expense account for supplier bills.', 'required' => true, 'types' => ['expense', 'asset']],
        'purchase_tax_recoverable' => ['label' => 'Input purchase tax recoverable', 'description' => 'Debited with recoverable purchase tax.', 'required' => false, 'types' => ['asset']],
        'withholding_tax_payable' => ['label' => 'Withholding tax payable', 'description' => 'Credited with tax withheld from supplier bills.', 'required' => false, 'types' => ['liability']],
        'inventory_asset' => ['label' => 'Inventory asset', 'description' => 'Inventory control account used for FIFO valuation.', 'required' => true, 'types' => ['asset']],
        'cogs' => ['label' => 'Cost of goods sold', 'description' => 'Debited with FIFO cost when inventory is issued.', 'required' => true, 'types' => ['expense']],
        'inventory_adjustment' => ['label' => 'Inventory adjustment / return clearing', 'description' => 'Gain, loss, and supplier-return clearing account for controlled inventory changes.', 'required' => true, 'types' => ['expense', 'revenue']],
    ];

    /** @return Collection<int, AccountMapping> */
    public function list(string $companyId): Collection
    {
        $existing = AccountMapping::query()->where('company_id', $companyId)->with('account')->get()->keyBy('key');

        return collect(self::DEFINITIONS)->map(function (array $definition, string $key) use ($companyId, $existing): AccountMapping {
            $mapping = $existing->get($key) ?? new AccountMapping(['company_id' => $companyId, 'key' => $key]);
            $mapping->setAttribute('label', $definition['label']);
            $mapping->setAttribute('description', $definition['description']);
            $mapping->setAttribute('required', $definition['required']);

            return $mapping;
        })->values();
    }

    public function set(string $companyId, string $key, ?string $accountId, int $userId): AccountMapping
    {
        $definition = self::DEFINITIONS[$key] ?? null;
        if ($definition === null) {
            abort(404);
        }
        if ($accountId !== null) {
            $valid = Account::query()->where('company_id', $companyId)->where('id', $accountId)->where('is_active', true)->whereIn('type', $definition['types'])->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['account_id' => 'The selected account has an incompatible type or does not belong to this company.']);
            }
        }
        $mapping = AccountMapping::query()->updateOrCreate(['company_id' => $companyId, 'key' => $key], ['account_id' => $accountId, 'updated_by' => $userId]);

        return $mapping->load('account');
    }

    public function require(string $companyId, string $key): Account
    {
        $account = AccountMapping::query()->where('company_id', $companyId)->where('key', $key)->with('account')->first()?->account;
        if ($account === null || ! $account->is_active) {
            throw ValidationException::withMessages(['account_mappings' => "Configure the {$key} account mapping before posting."]);
        }

        return $account;
    }
}
