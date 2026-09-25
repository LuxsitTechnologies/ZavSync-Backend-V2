<?php

namespace App\Http\Requests\Api\V1\Outreach;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOutreachSuppressionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'outreach.suppressions.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255'], 'reason' => ['required', 'in:MANUAL,INVALID,UNSUBSCRIBE,HARD_BOUNCE,COMPLAINT'], 'details' => ['nullable', 'string', 'max:5000']];
    }
}
