<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Models\AccountingPeriod;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAccountingPeriodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.periods.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date']];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }
            $overlaps = AccountingPeriod::query()->where('company_id', $this->attributes->get('company_id'))->whereDate('start_date', '<=', $this->date('end_date'))->whereDate('end_date', '>=', $this->date('start_date'))->exists();
            if ($overlaps) {
                $validator->errors()->add('start_date', 'Accounting periods cannot overlap.');
            }
        }];
    }
}
