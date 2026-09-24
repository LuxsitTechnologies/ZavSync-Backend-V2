<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\CrmDeal;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmDeal>
 */
class CrmDealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'account_id' => fn (array $attributes): string => CrmAccount::factory()->create(['company_id' => $attributes['company_id']])->id,
            'primary_contact_id' => null, 'lead_origin_id' => null,
            'pipeline_id' => fn (array $attributes): string => CrmPipeline::factory()->create(['company_id' => $attributes['company_id']])->id,
            'pipeline_stage_id' => fn (array $attributes): string => CrmPipelineStage::factory()->create(['company_id' => $attributes['company_id'], 'pipeline_id' => $attributes['pipeline_id']])->id,
            'owner_id' => null, 'customer_id' => null, 'title' => fake()->sentence(3),
            'amount' => fake()->numberBetween(100_000, 100_000_000), 'currency' => 'PKR', 'probability_bps' => 2500,
            'expected_close_date' => fake()->dateTimeBetween('now', '+4 months')->format('Y-m-d'), 'actual_close_date' => null,
            'status' => 'OPEN', 'source' => 'Website', 'description' => fake()->sentence(), 'loss_reason' => null,
            'closed_at' => null, 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}
