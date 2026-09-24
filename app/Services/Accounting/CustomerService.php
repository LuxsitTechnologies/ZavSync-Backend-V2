<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    /** @param array<string, mixed> $data */
    public function create(string $companyId, int $userId, array $data): Customer
    {
        return DB::transaction(function () use ($companyId, $userId, $data): Customer {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $sequence = (int) Customer::query()->where('company_id', $companyId)->max('sequence') + 1;

            return Customer::query()->create([
                ...$data, 'company_id' => $companyId, 'sequence' => $sequence,
                'code' => $data['code'] ?? sprintf('CUS-%04d', $sequence), 'created_by' => $userId,
            ]);
        });
    }
}
