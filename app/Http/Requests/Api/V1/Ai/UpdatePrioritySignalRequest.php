<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrioritySignalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'intelligence.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return ['action' => ['required', Rule::in(['ACKNOWLEDGE', 'RESOLVE', 'DISMISS', 'REOPEN', 'ASSIGN'])], 'note' => ['nullable', 'string', 'max:2000'], 'assigned_user_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))]];
    }
}
