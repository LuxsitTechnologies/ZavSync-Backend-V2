<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Validation\ValidationException;

class AccountHierarchyService
{
    public function validateParent(string $companyId, string $type, ?string $parentId, ?Account $account = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Account::query()->where('company_id', $companyId)->find($parentId);
        if (! $parent || $parent->type !== $type) {
            throw ValidationException::withMessages(['parent_id' => 'The parent must be an account of the same type in this company.']);
        }
        if ($account === null) {
            return;
        }

        $ancestorId = $parent->id;
        while ($ancestorId !== null) {
            if ($ancestorId === $account->id) {
                throw ValidationException::withMessages(['parent_id' => 'The selected parent would create a circular account hierarchy.']);
            }
            $ancestorId = Account::query()->where('company_id', $companyId)->whereKey($ancestorId)->value('parent_id');
        }
    }
}
