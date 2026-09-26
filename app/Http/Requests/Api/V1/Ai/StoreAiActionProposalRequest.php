<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiActionProposalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'ai.actions.propose') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $userId = $this->user()?->id;

        return [
            'action_type' => ['required', 'string', 'max:80'],
            'payload' => ['required', 'array'],
            'conversation_id' => ['nullable', 'uuid', Rule::exists('ai_conversations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('user_id', $userId))],
            'message_id' => ['nullable', 'uuid', Rule::exists('ai_messages', 'id')->where(function ($query) use ($companyId, $userId): void {
                $query->where('company_id', $companyId)->whereIn('ai_conversation_id', fn ($conversations) => $conversations->select('id')->from('ai_conversations')->where('company_id', $companyId)->where('user_id', $userId));
            })],
        ];
    }
}
