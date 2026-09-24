<?php

namespace App\Http\Requests\Api\V1\Crm;

use App\Support\CustomerValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerHandoffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.customer.convert') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return ['idempotency_key' => ['required', 'string', 'max:100'], 'customer_id' => ['nullable', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)], ...CustomerValidation::rules($companyId, 'required_without:customer_id', false)];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomerValidation::normalized($this->all()));
    }
}
