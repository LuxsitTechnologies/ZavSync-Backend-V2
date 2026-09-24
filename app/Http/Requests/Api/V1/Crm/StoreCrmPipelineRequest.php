<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmPipelineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.pipelines.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('crm_pipelines')->where('company_id', $companyId)->ignore($this->route('pipeline'))], 'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean'],
            'stages' => ['required', 'array', 'min:1', 'max:30'], 'stages.*.id' => ['sometimes', 'uuid', 'distinct'],
            'stages.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'], 'stages.*.position' => ['required', 'integer', 'min:1', 'max:1000', 'distinct'],
            'stages.*.probability_bps' => ['required', 'integer', 'between:0,10000'], 'stages.*.is_won' => ['sometimes', 'boolean'],
            'stages.*.is_lost' => ['sometimes', 'boolean'], 'stages.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            foreach ((array) $this->input('stages', []) as $index => $stage) {
                if (($stage['is_won'] ?? false) && ($stage['is_lost'] ?? false)) {
                    $validator->errors()->add("stages.$index.is_lost", 'A stage cannot be both won and lost.');
                }
            }
        }];
    }
}
