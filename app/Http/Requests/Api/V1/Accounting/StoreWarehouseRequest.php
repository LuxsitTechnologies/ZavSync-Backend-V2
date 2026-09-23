<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'inventory.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('warehouses')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'], 'is_default' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true, 'is_default' => $this->boolean('is_default')]);
    }
}
