<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.edit') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $accountId = (string) $this->route('account');

        return [
            'code' => ['required', 'regex:/^\d{3,8}$/', Rule::unique('accounts')->where('company_id', $companyId)->ignore($accountId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'subtype' => ['nullable', 'in:current_asset,non_current_asset,current_liability,non_current_liability,equity,revenue,cost_of_sales,operating_expense,other_income,other_expense'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['required', 'integer', 'between:-9007199254740991,9007199254740991'],
            'opening_balance_date' => ['nullable', 'date'],
        ];
    }
}
