<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Models\FiscalYear;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFiscalYearRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'budget.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after:start_date'], 'currency' => ['required', 'string', 'size:3']];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }
            $overlap = FiscalYear::query()->where('company_id', $this->attributes->get('company_id'))->whereDate('start_date', '<=', $this->date('end_date'))->whereDate('end_date', '>=', $this->date('start_date'))->exists();
            if ($overlap) {
                $validator->errors()->add('start_date', 'Fiscal years cannot overlap.');
            }
        }];
    }
}
