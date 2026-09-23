<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Journal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountingPeriodRequest extends FormRequest
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
        return ['name' => ['sometimes', 'string', 'max:255'], 'start_date' => ['sometimes', 'date'], 'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'], 'status' => ['sometimes', 'in:open,closed']];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $period = AccountingPeriod::query()->where('company_id', $this->attributes->get('company_id'))->find($this->route('period'));
            if (! $period || $validator->errors()->isNotEmpty()) {
                return;
            }
            $start = $this->date('start_date') ?? $period->start_date;
            $end = $this->date('end_date') ?? $period->end_date;
            if ($end->lt($start)) {
                $validator->errors()->add('end_date', 'The end date must be on or after the start date.');

                return;
            }
            $datesChanged = ! $start->isSameDay($period->start_date) || ! $end->isSameDay($period->end_date);
            $containsPostedJournals = Journal::query()->where('company_id', $period->company_id)->whereIn('status', ['posted', 'reversed'])->whereBetween('posting_date', [$period->start_date, $period->end_date])->exists();
            if ($datesChanged && $containsPostedJournals) {
                $validator->errors()->add('start_date', 'A period containing posted journals cannot have its dates changed.');

                return;
            }
            $overlaps = AccountingPeriod::query()->where('company_id', $this->attributes->get('company_id'))->whereKeyNot($period->id)->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
            if ($overlaps) {
                $validator->errors()->add('start_date', 'Accounting periods cannot overlap.');
            }
        }];
    }
}
