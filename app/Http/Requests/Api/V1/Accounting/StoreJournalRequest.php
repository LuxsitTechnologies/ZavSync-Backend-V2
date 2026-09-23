<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJournalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.post') === true;
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
            'status' => ['sometimes', 'in:draft'],
            'posting_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2'],
            'reference_type' => ['sometimes', 'in:invoice,customer_payment,supplier_bill,supplier_payment,inventory,payroll,manual_journal'],
            'source_id' => ['nullable', 'uuid'],
            'source' => ['sometimes', 'string', 'max:50'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.debit' => ['required', 'integer', 'between:0,9007199254740991'],
            'lines.*.credit' => ['required', 'integer', 'between:0,9007199254740991'],
            'lines.*.related_type' => ['nullable', 'string', 'max:50'],
            'lines.*.related_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('lines')) {
                return;
            }
            foreach ($this->input('lines', []) as $index => $line) {
                if (((int) ($line['debit'] ?? 0)) === 0 && ((int) ($line['credit'] ?? 0)) === 0) {
                    $validator->errors()->add("lines.$index", 'Each line must contain a debit or a credit.');
                }
                if (((int) ($line['debit'] ?? 0)) > 0 && ((int) ($line['credit'] ?? 0)) > 0) {
                    $validator->errors()->add("lines.$index", 'A line cannot contain both a debit and a credit.');
                }
            }
        }];
    }
}
