<?php

namespace App\Services\Crm;

use App\Exceptions\CrmException;
use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\CrmDeal;
use App\Models\Customer;
use App\Services\Accounting\CustomerService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmCustomerHandoffService
{
    public function __construct(private readonly CustomerService $customers, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function handoff(Request $request, Model $source, array $data): Customer
    {
        return DB::transaction(function () use ($request, $source, $data): Customer {
            $companyId = (string) $request->attributes->get('company_id');
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $locked = $source::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($source->getKey());
            if ($source::query()->where('company_id', $companyId)->where('customer_handoff_key', $data['idempotency_key'])->where('id', '!=', $locked->getKey())->exists()) {
                throw new CrmException('CRM_IDEMPOTENCY_KEY_REUSED', 'This idempotency key was already used for another customer handoff.', 409);
            }
            if ($locked instanceof CrmDeal && $locked->status !== 'WON') {
                throw new CrmException('CRM_DEAL_NOT_WON', 'Only a won deal can be handed off to the customer master.', 409);
            }
            if ($locked->customer_id) {
                return Customer::query()->where('company_id', $companyId)->findOrFail($locked->customer_id);
            }

            $customer = ! empty($data['customer_id'])
                ? Customer::query()->where('company_id', $companyId)->findOrFail($data['customer_id'])
                : $this->matchingCustomer($companyId, $data);
            if (! $customer) {
                $customer = $this->customers->create($companyId, (int) $request->user()->id, [
                    'name' => $data['name'], 'legal_name' => $data['legal_name'] ?? null, 'type' => $data['type'],
                    'ntn' => $data['ntn'] ?? null, 'cnic' => $data['cnic'] ?? null, 'strn' => null,
                    'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                    'billing_address' => $data['billing_address'] ?? null, 'city' => $data['city'] ?? null,
                    'province' => null, 'country' => $data['country'], 'postal_code' => $data['postal_code'] ?? null,
                    'contact_person' => $data['contact_person'] ?? null, 'payment_terms_days' => $data['payment_terms_days'],
                    'credit_limit' => $data['credit_limit'] ?? null, 'currency' => $data['currency'],
                    'tax_metadata' => null, 'is_active' => true, 'notes' => $data['notes'] ?? null,
                ]);
            }

            $old = $locked->toArray();
            $locked->update(['customer_id' => $customer->id, 'customer_handoff_key' => $data['idempotency_key'], 'updated_by' => $request->user()->id]);
            if ($locked instanceof CrmDeal) {
                CrmAccount::query()->where('company_id', $companyId)->whereKey($locked->account_id)->whereNull('customer_id')->update(['customer_id' => $customer->id, 'status' => 'CUSTOMER', 'updated_by' => $request->user()->id]);
            } else {
                $locked->update(['status' => 'CUSTOMER']);
            }
            $this->audit->record($request, $request->user(), $companyId, 'customer_handoff', $locked instanceof CrmDeal ? 'crm_deal' : 'crm_account', $locked, $old, $locked->fresh()->toArray());

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    private function matchingCustomer(string $companyId, array $data): ?Customer
    {
        $query = Customer::query()->where('company_id', $companyId);
        if (! empty($data['ntn'])) {
            return (clone $query)->where('ntn', $data['ntn'])->first();
        }
        if (! empty($data['cnic'])) {
            return (clone $query)->where('cnic', $data['cnic'])->first();
        }
        if (! empty($data['email'])) {
            return (clone $query)->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->first();
        }

        return $query->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();
    }
}
