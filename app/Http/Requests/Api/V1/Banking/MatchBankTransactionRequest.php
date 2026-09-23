<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatchBankTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.reconcile') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_reconciliation_id' => ['nullable', 'uuid'],
            'matchable_type' => ['required', Rule::in(['customer_payment', 'supplier_payment', 'journal', 'gateway_settlement', 'internal_transfer'])],
            'matchable_id' => ['required', 'uuid'],
            'amount' => ['nullable', 'integer', 'between:1,9007199254740991'],
            'confidence' => ['nullable', Rule::in(['exact', 'high', 'possible', 'manual'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
