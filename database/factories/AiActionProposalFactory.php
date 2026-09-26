<?php

namespace Database\Factories;

use App\Models\AiActionProposal;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiActionProposal>
 */
class AiActionProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['subject' => fake()->sentence(3), 'type' => 'TASK'];

        return ['company_id' => Company::factory(), 'created_by' => User::factory(), 'action_type' => 'CRM_ACTIVITY_DRAFT', 'status' => 'PENDING', 'payload' => $payload, 'impact_preview' => ['summary' => 'Create a CRM task draft.'], 'required_permission' => 'crm.activities.manage', 'payload_checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'idempotency_key' => fake()->uuid(), 'expires_at' => now()->addDay()];
    }
}
